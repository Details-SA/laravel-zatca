<?php

namespace Corecave\Zatca;

use Corecave\Zatca\Certificate\Certificate;
use Corecave\Zatca\Certificate\CertificateManager;
use Corecave\Zatca\Certificate\CsrGenerator;
use Corecave\Zatca\Contracts\ApiClientInterface;
use Corecave\Zatca\Contracts\CertificateInterface;
use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Debug\DebugDumper;
use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Exceptions\CertificateException;
use Corecave\Zatca\Hash\HashChainManager;
use Corecave\Zatca\Invoice\InvoiceBuilder;
use Corecave\Zatca\Models\ZatcaCertificate;
use Corecave\Zatca\Models\ZatcaInvoice;
use Corecave\Zatca\Qr\QrGenerator;
use Corecave\Zatca\Results\ClearanceResult;
use Corecave\Zatca\Results\ProcessResult;
use Corecave\Zatca\Results\ReportResult;
use Corecave\Zatca\Xml\UblGenerator;
use Corecave\Zatca\Xml\XmlSigner;
use Corecave\Zatca\Xml\XmlValidator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ZatcaManager
{
    protected Application $app;

    protected ?ApiClientInterface $client = null;

    protected ?DebugDumper $debugDumper = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Get the invoice builder.
     */
    public function invoice(): InvoiceBuilder
    {
        return $this->app->make(InvoiceBuilder::class);
    }

    /**
     * Get the certificate manager.
     */
    public function certificate(): CertificateManager
    {
        return $this->app->make(CertificateManager::class);
    }

    /**
     * Get the CSR generator.
     */
    public function csr(): CsrGenerator
    {
        return $this->app->make(CsrGenerator::class);
    }

    /**
     * Generate a CSR programmatically with tenant-specific parameters.
     *
     * @param  array  $params  CSR parameters (override config/zatca.php values)
     * @param  string|null  $environment  Override environment for serial number generation
     * @return array{csr: string, private_key: string, public_key: string, serial_number: string}
     */
    public function generateCsr(array $params = [], ?string $environment = null): array
    {
        [$config, $serialNumber] = $this->buildCsrConfig($params, $environment);

        $result = $this->csr()->generate($config);
        $result['serial_number'] = $serialNumber;

        return $result;
    }

    /**
     * Request a Compliance CSID and optionally run compliance checks.
     *
     * Options:
     * - csr (string) and private_key (string): provide an existing CSR pair
     * - csr_params (array): parameters to generate CSR programmatically
     * - environment (string|null): override environment for CSR serial + API client
     * - store (bool): store compliance certificate (default true)
     * - run_checks (bool): run compliance checks (default true)
     * - check_options (array): options passed to runComplianceChecks()
     * - client (ApiClientInterface): custom client instance
     * - client_config (array): override HTTP client config
     *
     * @return array{response: array, certificate: Certificate, csr: string, private_key: string, serial_number?: string, checks?: array}
     */
    public function runCompliance(string $otp, array $options = []): array
    {
        $store = $options['store'] ?? true;
        $runChecks = $options['run_checks'] ?? true;
        $environment = $options['environment'] ?? null;

        $csr = $options['csr'] ?? null;
        $privateKey = $options['private_key'] ?? null;
        $serialNumber = null;

        if (($csr === null) !== ($privateKey === null)) {
            throw new \InvalidArgumentException('Both csr and private_key must be provided together.');
        }

        if ($csr === null) {
            $csrParams = $options['csr_params'] ?? [];
            $csrResult = $this->generateCsr($csrParams, $environment);
            $csr = $csrResult['csr'];
            $privateKey = $csrResult['private_key'];
            $serialNumber = $csrResult['serial_number'] ?? null;
        }

        $client = $options['client']
            ?? $this->clientForEnvironment($environment, $options['client_config'] ?? []);

        $response = $client->requestComplianceCsid($csr, $otp);
        $certificate = Certificate::fromApiResponse($response, $privateKey, 'compliance');

        if ($store) {
            $this->certificate()->store($certificate);
        }

        $result = [
            'response' => $response,
            'certificate' => $certificate,
            'csr' => $csr,
            'private_key' => $privateKey,
        ];

        if ($serialNumber !== null) {
            $result['serial_number'] = $serialNumber;
        }

        if ($runChecks) {
            $checkOptions = $options['check_options'] ?? [];
            $checkOptions['certificate'] = $certificate;
            $checkOptions['environment'] = $environment;
            $result['checks'] = $this->runComplianceChecks($certificate, $checkOptions);
        }

        return $result;
    }

    /**
     * Run compliance checks using an existing compliance certificate.
     *
     * Options:
     * - certificate (Certificate): compliance certificate override
     * - type (string): all|simplified|standard (default all)
     * - invoice_types (string): 1100|1000|0100 (default config)
     * - seller (array): override seller data for sample invoices
     * - buyer (array): override buyer data for standard invoices
     * - line_item (array): override default line item
     * - save_xml (bool): save generated XML
     * - save_path (string): directory to save XML
     * - environment (string|null): override environment for API client
     * - client (ApiClientInterface): custom client instance
     * - client_config (array): override HTTP client config
     *
     * @return array{passed:int, failed:int, total:int, results: array}
     */
    public function runComplianceChecks(?Certificate $certificate = null, array $options = []): array
    {
        $certificate = $options['certificate'] ?? $certificate ?? $this->certificate()->getActive('compliance');

        if (! $certificate) {
            throw CertificateException::notFound('compliance');
        }

        $environment = $options['environment'] ?? null;
        $client = $options['client']
            ?? $this->clientForEnvironment($environment, $options['client_config'] ?? []);
        $client->setCertificate($certificate);

        $invoiceTypes = $options['invoice_types'] ?? config('zatca.csr.invoice_types', '1100');
        $typeFilter = $options['type'] ?? 'all';

        $sampleInvoices = [];

        if (in_array($invoiceTypes, ['1000', '1100'], true) && in_array($typeFilter, ['all', 'simplified'], true)) {
            $sampleInvoices[] = ['type' => 'simplified', 'subtype' => 'invoice'];
            $sampleInvoices[] = ['type' => 'simplified', 'subtype' => 'credit_note'];
            $sampleInvoices[] = ['type' => 'simplified', 'subtype' => 'debit_note'];
        }

        if (in_array($invoiceTypes, ['0100', '1100'], true) && in_array($typeFilter, ['all', 'standard'], true)) {
            $sampleInvoices[] = ['type' => 'standard', 'subtype' => 'invoice'];
            $sampleInvoices[] = ['type' => 'standard', 'subtype' => 'credit_note'];
            $sampleInvoices[] = ['type' => 'standard', 'subtype' => 'debit_note'];
        }

        if (empty($sampleInvoices)) {
            return ['passed' => 0, 'failed' => 0, 'total' => 0, 'results' => []];
        }

        $seller = $options['seller'] ?? null;
        $buyer = $options['buyer'] ?? null;
        $lineItem = $options['line_item'] ?? null;
        $saveXml = (bool) ($options['save_xml'] ?? false);
        $savePath = $options['save_path'] ?? storage_path('zatca/debug');

        $passed = 0;
        $failed = 0;
        $results = [];

        foreach ($sampleInvoices as $index => $sample) {
            try {
                $invoice = $this->buildComplianceSampleInvoice($sample, $index + 1, $seller, $buyer, $lineItem);

                $xml = $this->xml()->generate($invoice);
                $hash = $this->signer()->generateInvoiceHash($xml);
                $invoice->setHash($hash);

                $signedXml = $this->signer()->sign(
                    $xml,
                    $certificate->getPrivateKey(),
                    $certificate->getCertificatePem()
                );

                $signatureValue = $this->signer()->getSignatureValue($signedXml);
                $publicKeyDer = $certificate->getPublicKeyRaw();
                $certificateSignature = $certificate->getCertificateSignature();
                $qrCode = $this->qr()->generate($invoice, $signatureValue, $publicKeyDer, $certificateSignature);

                $signedXml = $this->xml()->addQrCode($signedXml, $qrCode);

                if ($saveXml) {
                    if (! is_dir($savePath)) {
                        mkdir($savePath, 0755, true);
                    }
                    $filename = $savePath.'/'.($sample['type'].'_'.$sample['subtype'].'.xml');
                    file_put_contents($filename, $signedXml);
                }

                $response = $client->submitComplianceInvoice(
                    $signedXml,
                    $hash,
                    $invoice->getUuid()
                );

                $status = strtoupper($response['validationResults']['status'] ?? 'UNKNOWN');
                $messages = $this->extractValidationMessages($response);

                $ok = in_array($status, ['PASS', 'WARNING'], true);
                if ($ok) {
                    $passed++;
                } else {
                    $failed++;
                }

                $results[] = [
                    'type' => $sample['type'],
                    'subtype' => $sample['subtype'],
                    'status' => $status,
                    'passed' => $ok,
                    'response' => $response,
                    'messages' => $messages,
                ];
            } catch (\Exception $e) {
                $failed++;
                $results[] = [
                    'type' => $sample['type'],
                    'subtype' => $sample['subtype'],
                    'status' => 'ERROR',
                    'passed' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'passed' => $passed,
            'failed' => $failed,
            'total' => $passed + $failed,
            'results' => $results,
        ];
    }

    /**
     * Request a Production CSID after completing compliance checks.
     *
     * Options:
     * - certificate (Certificate): compliance certificate override
     * - store (bool): store production certificate (default true)
     * - environment (string|null): override environment for API client
     * - client (ApiClientInterface): custom client instance
     * - client_config (array): override HTTP client config
     *
     * @return array{response: array, certificate: Certificate}
     */
    public function requestProductionCsid(?string $requestId = null, ?Certificate $complianceCert = null, array $options = []): array
    {
        $complianceCert = $options['certificate'] ?? $complianceCert ?? $this->certificate()->getActive('compliance');

        if (! $complianceCert) {
            throw CertificateException::notFound('compliance');
        }

        $requestId = $requestId ?? $complianceCert->getRequestId();
        if (empty($requestId)) {
            throw new \InvalidArgumentException('Compliance request ID is required.');
        }

        $environment = $options['environment'] ?? null;
        $client = $options['client']
            ?? $this->clientForEnvironment($environment, $options['client_config'] ?? []);
        $client->setCertificate($complianceCert);

        $response = $client->requestProductionCsid($requestId);
        $productionCert = Certificate::fromApiResponse(
            $response,
            $complianceCert->getPrivateKey(),
            'production'
        );

        if (($options['store'] ?? true) === true) {
            $this->certificate()->store($productionCert);
        }

        return [
            'response' => $response,
            'certificate' => $productionCert,
        ];
    }

    /**
     * Renew a Production CSID.
     *
     * Options:
     * - certificate (Certificate): current production certificate override
     * - csr (string) and private_key (string): provide an existing CSR pair
     * - csr_params (array): parameters to generate CSR programmatically
     * - environment (string|null): override environment for CSR serial + API client
     * - store (bool): store renewed certificate (default true)
     * - client (ApiClientInterface): custom client instance
     * - client_config (array): override HTTP client config
     *
     * @return array{response: array, certificate: Certificate, csr: string, private_key: string, serial_number?: string}
     */
    public function renewProductionCsid(string $otp, array $options = []): array
    {
        $currentCert = $options['certificate'] ?? $this->certificate()->getActive('production');

        if (! $currentCert) {
            throw CertificateException::notFound('production');
        }

        $environment = $options['environment'] ?? null;
        $csr = $options['csr'] ?? null;
        $privateKey = $options['private_key'] ?? null;
        $serialNumber = null;

        if (($csr === null) !== ($privateKey === null)) {
            throw new \InvalidArgumentException('Both csr and private_key must be provided together.');
        }

        if ($csr === null) {
            $csrParams = $options['csr_params'] ?? [];
            $csrResult = $this->generateCsr($csrParams, $environment);
            $csr = $csrResult['csr'];
            $privateKey = $csrResult['private_key'];
            $serialNumber = $csrResult['serial_number'] ?? null;
        }

        $client = $options['client']
            ?? $this->clientForEnvironment($environment, $options['client_config'] ?? []);
        $client->setCertificate($currentCert);

        $response = $client->renewProductionCsid($csr, $otp);
        $newCert = Certificate::fromApiResponse($response, $privateKey, 'production');

        if (($options['store'] ?? true) === true) {
            $this->certificate()->store($newCert);
        }

        $result = [
            'response' => $response,
            'certificate' => $newCert,
            'csr' => $csr,
            'private_key' => $privateKey,
        ];

        if ($serialNumber !== null) {
            $result['serial_number'] = $serialNumber;
        }

        return $result;
    }

    /**
     * Cleanup utility (programmatic alternative to the cleanup command).
     *
     * Options:
     * - certificates, compliance, production, csr, invoices, debug, all (bool)
     *
     * @return array<string,int>
     */
    public function cleanup(array $options = []): array
    {
        $all = (bool) ($options['all'] ?? false);

        $results = [
            'compliance_certificates' => 0,
            'production_certificates' => 0,
            'csr_files' => 0,
            'invoices' => 0,
            'debug_files' => 0,
        ];

        if ($all || ($options['compliance'] ?? false) || ($options['certificates'] ?? false)) {
            $results['compliance_certificates'] = ZatcaCertificate::where('type', 'compliance')->count();
            ZatcaCertificate::where('type', 'compliance')->delete();
        }

        if ($all || ($options['production'] ?? false) || ($options['certificates'] ?? false)) {
            $results['production_certificates'] = ZatcaCertificate::where('type', 'production')->count();
            ZatcaCertificate::where('type', 'production')->delete();
        }

        if ($all || ($options['csr'] ?? false)) {
            $storagePath = storage_path('zatca');
            $files = ['csr.pem', 'private_key.pem'];

            foreach ($files as $filename) {
                $path = $storagePath.'/'.$filename;
                if (File::exists($path)) {
                    File::delete($path);
                    $results['csr_files']++;
                }
            }
        }

        if ($all || ($options['invoices'] ?? false)) {
            $results['invoices'] = ZatcaInvoice::count();
            ZatcaInvoice::truncate();
            $this->resetIcvCounter();
        }

        if ($all || ($options['debug'] ?? false)) {
            $debugPath = storage_path(config('zatca.debug.path', 'zatca/debug'));
            if (File::isDirectory($debugPath)) {
                $files = File::allFiles($debugPath);
                $results['debug_files'] = count($files);
                File::cleanDirectory($debugPath);
            }
        }

        return $results;
    }

    /**
     * Get the API client.
     */
    public function client(): ApiClientInterface
    {
        if ($this->client === null) {
            $this->client = $this->app->make(ApiClientInterface::class);

            // Set the production certificate if available
            $certificate = $this->certificate()->getActive('production');
            if ($certificate) {
                $this->client->setCertificate($certificate);
            }
        }

        return $this->client;
    }

    /**
     * Get the UBL generator.
     */
    public function xml(): UblGenerator
    {
        return $this->app->make(UblGenerator::class);
    }

    /**
     * Get the XML signer.
     */
    public function signer(): XmlSigner
    {
        return $this->app->make(XmlSigner::class);
    }

    /**
     * Get the XML validator.
     */
    public function validator(): XmlValidator
    {
        return $this->app->make(XmlValidator::class);
    }

    /**
     * Get the QR code generator.
     */
    public function qr(): QrGenerator
    {
        return $this->app->make(QrGenerator::class);
    }

    /**
     * Get the hash chain manager.
     */
    public function hashChain(): HashChainManager
    {
        return $this->app->make(HashChainManager::class);
    }

    /**
     * Get the debug dumper.
     */
    public function debug(): DebugDumper
    {
        if ($this->debugDumper === null) {
            $this->debugDumper = $this->app->make(DebugDumper::class);
        }

        return $this->debugDumper;
    }

    /**
     * Build CSR config and serial number from defaults + overrides.
     *
     * @return array{0: array, 1: string}
     */
    protected function buildCsrConfig(array $params, ?string $environment = null): array
    {
        if (array_key_exists('environment', $params)) {
            $environment = $params['environment'];
            unset($params['environment']);
        }

        $config = array_replace_recursive(config('zatca.csr', []), $params);

        if (empty($config['country'])) {
            $config['country'] = 'SA';
        }

        if (empty($config['serial_number'])) {
            $environment = $environment ?? config('zatca.environment', 'sandbox');
            $serialPrefix = in_array($environment, ['sandbox', 'simulation'], true)
                ? 'TST'
                : ($config['organization'] ?? 'TST');

            $uuid = (string) Str::uuid();
            $config['serial_number'] = sprintf('1-%s|2-%s|3-%s', $serialPrefix, $serialPrefix, $uuid);
        }

        return [$config, $config['serial_number']];
    }

    /**
     * Build a client for a specific environment or with config overrides.
     */
    protected function clientForEnvironment(?string $environment = null, array $configOverrides = []): ApiClientInterface
    {
        if ($environment === null && empty($configOverrides)) {
            return $this->client();
        }

        $config = array_merge(
            config('zatca.http', []),
            ['api_version' => config('zatca.api_version', 'V2')],
            $configOverrides
        );

        $environment = $environment ?? config('zatca.environment', 'sandbox');

        return new \Corecave\Zatca\Client\ZatcaClient($environment, $config);
    }

    /**
     * Build a sample invoice for compliance testing.
     */
    protected function buildComplianceSampleInvoice(
        array $sample,
        int $number,
        ?array $seller = null,
        ?array $buyer = null,
        ?array $lineItem = null
    ): InvoiceInterface {
        $isSimplified = $sample['type'] === 'simplified';
        $isCredit = $sample['subtype'] === 'credit_note';
        $isDebit = $sample['subtype'] === 'debit_note';

        if ($isCredit) {
            $builder = InvoiceBuilder::creditNote($isSimplified);
            $builder->setOriginalInvoice('SME00001');
            $builder->setReason('Cancellation or Returned');
        } elseif ($isDebit) {
            $builder = InvoiceBuilder::debitNote($isSimplified);
            $builder->setOriginalInvoice('SME00001');
            $builder->setReason('Price adjustment or Additional charges');
        } else {
            $builder = $isSimplified
                ? InvoiceBuilder::simplified()
                : InvoiceBuilder::standard();
        }

        $builder->setInvoiceNumber("SME0000{$number}");

        if ($seller !== null) {
            $builder->setSeller($seller);
        }

        if (! $isSimplified) {
            if ($buyer !== null) {
                $builder->setBuyer($buyer);
            } else {
                $builder->setBuyer([
                    'name' => 'Test Buyer Company',
                    'vat_number' => '300000000000003',
                    'registration_number' => '1234567890',
                    'registration_scheme' => 'CRN',
                    'address' => [
                        'street' => 'King Fahd Road',
                        'building' => '1234',
                        'additional_number' => '5678',
                        'city' => 'Riyadh',
                        'district' => 'Al Olaya',
                        'postal_code' => '12345',
                        'country' => 'SA',
                    ],
                ]);
            }
        }

        $builder->addLineItem($lineItem ?? [
            'name' => 'Test Product',
            'quantity' => 1,
            'unit_price' => 100.00,
            'vat_category' => VatCategory::STANDARD,
            'vat_rate' => 15.00,
        ]);

        return $builder->build();
    }

    /**
     * Extract validation messages from a compliance response.
     */
    protected function extractValidationMessages(array $response): array
    {
        $results = $response['validationResults'] ?? [];

        return [
            'info' => $results['infoMessages'] ?? [],
            'warning' => $results['warningMessages'] ?? [],
            'error' => $results['errorMessages'] ?? [],
        ];
    }

    /**
     * Reset ICV counter.
     */
    protected function resetIcvCounter(): void
    {
        $tableName = config('zatca.tables.invoices', 'zatca_invoices');

        try {
            DB::statement("ALTER TABLE {$tableName} AUTO_INCREMENT = 1");
        } catch (\Exception $e) {
            // Ignore if not supported
        }
    }

    /**
     * Report a simplified invoice (B2C).
     */
    public function report(InvoiceInterface $invoice): ReportResult
    {
        $certificate = $this->certificate()->getActive('production');

        if (! $certificate) {
            throw CertificateException::notFound('production');
        }

        // Generate XML
        $xml = $this->xml()->generate($invoice);

        // Debug: dump unsigned XML
        $this->debug()->dumpUnsignedXml($xml, $invoice->getInvoiceNumber());

        // Generate hash
        $hash = $this->signer()->generateInvoiceHash($xml);
        $invoice->setHash($hash);

        // Debug: dump hash
        $this->debug()->dumpHash($hash, $invoice->getInvoiceNumber());

        // Sign XML
        $signedXml = $this->signer()->sign(
            $xml,
            $certificate->getPrivateKey(),
            $certificate->getCertificatePem()
        );

        // Generate QR code (per ZATCA spec):
        // Tag 7 = SignatureValue (base64 string)
        // Tag 8 = Public Key (raw DER bytes)
        // Tag 9 = Certificate Signature (raw bytes from X.509 cert)
        $signatureValue = $this->signer()->getSignatureValue($signedXml);
        $publicKeyDer = $certificate->getPublicKeyRaw();
        $certificateSignature = $certificate->getCertificateSignature();
        $qrCode = $this->qr()->generate($invoice, $signatureValue, $publicKeyDer, $certificateSignature);

        // Debug: dump QR code
        $this->debug()->dumpQrCode($qrCode, $invoice->getInvoiceNumber());

        // Add QR code to XML
        $signedXml = $this->xml()->addQrCode($signedXml, $qrCode);

        // Debug: dump signed XML (with QR code)
        $this->debug()->dumpSignedXml($signedXml, $invoice->getInvoiceNumber());

        // Submit to ZATCA
        $this->client()->setCertificate($certificate);
        $response = $this->client()->reportInvoice($signedXml, $hash, $invoice->getUuid());

        return new ReportResult($invoice, $signedXml, $qrCode, $response);
    }

    /**
     * Clear a standard invoice (B2B).
     */
    public function clear(InvoiceInterface $invoice): ClearanceResult
    {
        $certificate = $this->certificate()->getActive('production');

        if (! $certificate) {
            throw CertificateException::notFound('production');
        }

        // Generate XML
        $xml = $this->xml()->generate($invoice);

        // Debug: dump unsigned XML
        $this->debug()->dumpUnsignedXml($xml, $invoice->getInvoiceNumber());

        // Generate hash
        $hash = $this->signer()->generateInvoiceHash($xml);
        $invoice->setHash($hash);

        // Debug: dump hash
        $this->debug()->dumpHash($hash, $invoice->getInvoiceNumber());

        // Sign XML
        $signedXml = $this->signer()->sign(
            $xml,
            $certificate->getPrivateKey(),
            $certificate->getCertificatePem()
        );

        // Debug: dump signed XML (before clearance)
        $this->debug()->dumpSignedXml($signedXml, $invoice->getInvoiceNumber());

        // Submit to ZATCA for clearance
        $this->client()->setCertificate($certificate);
        $response = $this->client()->clearInvoice($signedXml, $hash, $invoice->getUuid());

        // Extract cleared XML and QR code from response
        $clearedXml = $response['clearedInvoice'] ?? $signedXml;
        $qrCode = $this->xml()->extractQrCode($clearedXml);

        // Debug: dump cleared XML and QR code from ZATCA
        if ($clearedXml !== $signedXml) {
            $this->debug()->dumpSignedXml($clearedXml, $invoice->getInvoiceNumber().'_cleared');
        }
        if ($qrCode) {
            $this->debug()->dumpQrCode($qrCode, $invoice->getInvoiceNumber());
        }

        return new ClearanceResult($invoice, $clearedXml, $qrCode, $response);
    }

    /**
     * Process an invoice (automatically determines report or clear).
     */
    public function process(InvoiceInterface $invoice): ProcessResult
    {
        if ($invoice->isSimplified()) {
            $result = $this->report($invoice);

            return new ProcessResult($invoice, $result, 'reported');
        }

        $result = $this->clear($invoice);

        return new ProcessResult($invoice, $result, 'cleared');
    }

    /**
     * Generate XML for an invoice.
     */
    public function generateXml(InvoiceInterface $invoice): string
    {
        return $this->xml()->generate($invoice);
    }

    /**
     * Sign XML with the production certificate.
     */
    public function signXml(string $xml): string
    {
        $certificate = $this->certificate()->getActive('production');

        if (! $certificate) {
            throw CertificateException::notFound('production');
        }

        return $this->signer()->sign(
            $xml,
            $certificate->getPrivateKey(),
            $certificate->getCertificatePem()
        );
    }

    /**
     * Validate XML against ZATCA schema.
     */
    public function validateXml(string $xml): bool
    {
        return $this->validator()->validate($xml);
    }

    /**
     * Generate QR code for an invoice.
     *
     * @param  InvoiceInterface  $invoice  The invoice
     * @param  string  $signatureValue  Base64 encoded SignatureValue from XML signature
     * @param  string  $publicKey  Public key - raw SPKI DER bytes
     * @param  string  $certificateSignature  Raw certificate signature bytes
     */
    public function generateQrCode(
        InvoiceInterface $invoice,
        string $signatureValue,
        string $publicKey,
        string $certificateSignature
    ): string {
        return $this->qr()->generate($invoice, $signatureValue, $publicKey, $certificateSignature);
    }

    /**
     * Check if running in sandbox mode.
     */
    public function isSandbox(): bool
    {
        return config('zatca.environment') === 'sandbox';
    }

    /**
     * Get or create a fresh certificate for the current environment.
     *
     * In sandbox mode, this will automatically generate a new CSR and
     * obtain a compliance certificate if none exists or if forceNew is true.
     *
     * @param  bool  $forceNew  Force generation of a new certificate
     *
     * @throws CertificateException
     */
    public function getOrCreateCertificate(bool $forceNew = false): CertificateInterface
    {
        $certManager = $this->certificate();

        // Check for existing certificate
        if (! $forceNew) {
            $cert = $certManager->getActive('compliance') ?? $certManager->getActive('production');
            if ($cert && $cert->isActive()) {
                // Verify the certificate VAT matches config
                if ($this->certificateMatchesConfig($cert)) {
                    return $cert;
                }
                Log::info('ZATCA: Existing certificate VAT does not match config, generating new certificate');
            }
        }

        // In sandbox mode, auto-generate certificate
        if ($this->isSandbox()) {
            return $this->autoOnboard();
        }

        throw CertificateException::notFound('compliance or production');
    }

    /**
     * Automatically onboard and get a compliance certificate.
     *
     * This is primarily for sandbox mode where OTP can be any value.
     * For simulation/production, use the zatca:compliance command.
     *
     * @param  string  $otp  OTP code (default: '123456' for sandbox)
     *
     * @throws CertificateException
     */
    public function autoOnboard(string $otp = '123456'): CertificateInterface
    {
        Log::info('ZATCA: Starting auto-onboard process');

        // Generate fresh CSR with config values
        $csrGenerator = $this->csr();
        $csrConfig = config('zatca.csr');

        $csrData = $csrGenerator->generate([
            'organization' => $csrConfig['organization'],
            'organization_unit' => $csrConfig['organization_unit'],
            'common_name' => $csrConfig['common_name'],
            'vat_number' => $csrConfig['vat_number'],
            'invoice_types' => $csrConfig['invoice_types'] ?? '1100',
            'location' => $csrConfig['location'] ?? [],
            'business_category' => $csrConfig['business_category'] ?? 'Technology',
        ]);

        Log::info('ZATCA: CSR generated successfully');

        // Request compliance CSID from ZATCA
        $client = $this->app->make(ApiClientInterface::class);

        try {
            $response = $client->requestComplianceCsid($csrData['csr'], $otp);
            Log::info('ZATCA: Compliance CSID received', [
                'request_id' => $response['requestID'] ?? 'N/A',
            ]);
        } catch (\Exception $e) {
            Log::error('ZATCA: Failed to get compliance CSID', ['error' => $e->getMessage()]);
            throw CertificateException::csrGenerationFailed('Failed to get compliance CSID: '.$e->getMessage());
        }

        // Create and store certificate
        $certificate = Certificate::fromApiResponse($response, $csrData['private_key'], 'compliance');

        // Deactivate old certificates and store new one
        $certManager = $this->certificate();
        $certManager->deactivateAll('compliance');
        $certManager->store($certificate);

        Log::info('ZATCA: Compliance certificate stored successfully');

        return $certificate;
    }

    /**
     * Check if a certificate's embedded VAT matches the config VAT.
     */
    protected function certificateMatchesConfig(CertificateInterface $cert): bool
    {
        try {
            $configVat = config('zatca.csr.vat_number');
            if (! $configVat) {
                return true; // No config VAT to check against
            }

            $x509 = new \phpseclib3\File\X509;
            $certData = $x509->loadX509($cert->getCertificatePem());

            // Find VAT in Subject Alternative Name extension
            if (isset($certData['tbsCertificate']['extensions'])) {
                foreach ($certData['tbsCertificate']['extensions'] as $ext) {
                    if (isset($ext['extnId']) && $ext['extnId'] === 'id-ce-subjectAltName') {
                        foreach ($ext['extnValue'] as $san) {
                            if (isset($san['directoryName']['rdnSequence'])) {
                                foreach ($san['directoryName']['rdnSequence'] as $rdn) {
                                    foreach ($rdn as $attr) {
                                        // OID for UID (VAT Number)
                                        if ($attr['type'] === '0.9.2342.19200300.100.1.1') {
                                            $certVat = $attr['value']['utf8String'] ?? null;

                                            return $certVat === $configVat;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::warning('ZATCA: Could not verify certificate VAT', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Generate a fresh certificate and sign an invoice in one call.
     *
     * This is useful for sandbox testing where you want a completely
     * fresh certificate for each invoice.
     *
     * @return array{certificate: CertificateInterface, signedXml: string, hash: string}
     */
    public function signWithFreshCertificate(InvoiceInterface $invoice): array
    {
        // Force new certificate
        $certificate = $this->getOrCreateCertificate(forceNew: true);

        // Generate XML
        $xml = $this->xml()->generate($invoice);

        // Debug: dump unsigned XML
        $this->debug()->dumpUnsignedXml($xml, $invoice->getInvoiceNumber());

        // Generate hash
        $hash = $this->signer()->generateInvoiceHash($xml);
        $invoice->setHash($hash);

        // Debug: dump hash
        $this->debug()->dumpHash($hash, $invoice->getInvoiceNumber());

        // Sign XML
        $signedXml = $this->signer()->sign(
            $xml,
            $certificate->getPrivateKey(),
            $certificate->getCertificatePem()
        );

        // Debug: dump signed XML
        $this->debug()->dumpSignedXml($signedXml, $invoice->getInvoiceNumber());

        return [
            'certificate' => $certificate,
            'signedXml' => $signedXml,
            'hash' => $hash,
        ];
    }
}
