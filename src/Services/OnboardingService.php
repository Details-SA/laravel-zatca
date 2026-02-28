<?php

namespace Corecave\Zatca\Services;

use Corecave\Zatca\Certificate\Certificate;
use Corecave\Zatca\Certificate\CertificateManager;
use Corecave\Zatca\Certificate\CsrGenerator;
use Corecave\Zatca\Contracts\ApiClientInterface;
use Corecave\Zatca\DTO\OnboardingProfile;
use Corecave\Zatca\Enums\VatCategory;
use Corecave\Zatca\Invoice\InvoiceBuilder;
use Corecave\Zatca\Qr\QrGenerator;
use Corecave\Zatca\Xml\UblGenerator;
use Corecave\Zatca\Xml\XmlSigner;
use Illuminate\Support\Str;

class OnboardingService
{
    protected CsrGenerator $csrGenerator;
    protected CertificateManager $certManager;
    protected ApiClientInterface $client;
    protected UblGenerator $xmlGenerator;
    protected XmlSigner $signer;
    protected QrGenerator $qrGenerator;

    public function __construct(
        CsrGenerator $csrGenerator,
        CertificateManager $certManager,
        ApiClientInterface $client,
        UblGenerator $xmlGenerator,
        XmlSigner $signer,
        QrGenerator $qrGenerator
        )
    {
        $this->csrGenerator = $csrGenerator;
        $this->certManager = $certManager;
        $this->client = $client;
        $this->xmlGenerator = $xmlGenerator;
        $this->signer = $signer;
        $this->qrGenerator = $qrGenerator;
    }

    /**
     * Generate CSR and Private Key based on OnboardingProfile.
     *
     * @return array{csr: string, private_key: string, public_key: string}
     */
    public function generateCsr(OnboardingProfile $profile): array
    {
        if (empty($profile->serialNumber)) {
            $uuid = (string)Str::uuid();
            $serialPrefix = in_array($profile->environment, ['sandbox', 'simulation']) ? 'TST' : $profile->organization;
            $profile->serialNumber = sprintf(
                '1-%s|2-%s|3-%s',
                $serialPrefix,
                $serialPrefix, // Also use prefix for the version part in test environments
                $uuid
            );
        }

        return $this->csrGenerator->generate($profile->toArray());
    }

    /**
     * Obtain a Compliance CSID (Certificate) from ZATCA.
     */
    public function getComplianceCsid(string $csr, string $privateKey, string $otp): Certificate
    {
        $response = $this->client->requestComplianceCsid($csr, $otp);

        $complianceCert = Certificate::fromApiResponse($response, $privateKey, 'compliance');
        $this->certManager->store($complianceCert);

        return $complianceCert;
    }

    /**
     * Run compliance checks by submitting sample invoices.
     * Returns an array with 'passed' boolean and 'results' containing detailed check info.
     */
    public function runComplianceChecks(Certificate $certificate, string $invoiceTypes = '1100'): array
    {
        $this->client->setCertificate($certificate);

        $sampleInvoices = [];

        // B2C samples
        if (in_array($invoiceTypes, ['1000', '1100'])) {
            $sampleInvoices[] = ['type' => 'simplified', 'subtype' => 'invoice'];
            $sampleInvoices[] = ['type' => 'simplified', 'subtype' => 'credit_note'];
            $sampleInvoices[] = ['type' => 'simplified', 'subtype' => 'debit_note'];
        }

        // B2B samples
        if (in_array($invoiceTypes, ['0100', '1100'])) {
            $sampleInvoices[] = ['type' => 'standard', 'subtype' => 'invoice'];
            $sampleInvoices[] = ['type' => 'standard', 'subtype' => 'credit_note'];
            $sampleInvoices[] = ['type' => 'standard', 'subtype' => 'debit_note'];
        }

        $results = [];
        $passedCount = 0;
        $failedCount = 0;

        foreach ($sampleInvoices as $index => $sample) {
            $invoice = $this->buildSampleInvoice($sample, $index + 1);

            $xml = $this->xmlGenerator->generate($invoice);
            $hash = $this->signer->generateInvoiceHash($xml);
            $invoice->setHash($hash);

            $signedXml = $this->signer->sign(
                $xml,
                $certificate->getPrivateKey(),
                $certificate->getCertificatePem()
            );

            $signatureValue = $this->signer->getSignatureValue($signedXml);
            $publicKeyDer = $certificate->getPublicKeyRaw();
            $certificateSignature = $certificate->getCertificateSignature();
            $qrCode = $this->qrGenerator->generate($invoice, $signatureValue, $publicKeyDer, $certificateSignature);

            $signedXml = $this->xmlGenerator->addQrCode($signedXml, $qrCode);

            try {
                $response = $this->client->submitComplianceInvoice($signedXml, $hash, $invoice->getUuid());

                $status = strtoupper($response['validationResults']['status'] ?? 'UNKNOWN');
                $reportingStatus = $response['reportingStatus'] ?? null;
                $clearanceStatus = $response['clearanceStatus'] ?? null;

                $isAccepted = in_array($status, ['PASS', 'WARNING'])
                    && ($reportingStatus === 'REPORTED' || $clearanceStatus === 'CLEARED');

                $results[] = [
                    'sample' => $sample,
                    'status' => $status,
                    'accepted' => $isAccepted,
                    'response' => $response,
                ];

                if ($isAccepted) {
                    $passedCount++;
                }
                else {
                    $failedCount++;
                }
            }
            catch (\Exception $e) {
                $results[] = [
                    'sample' => $sample,
                    'status' => 'ERROR',
                    'accepted' => false,
                    'error' => $e->getMessage(),
                ];
                $failedCount++;
            }
        }

        return [
            'passed' => $failedCount === 0,
            'passed_count' => $passedCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }

    /**
     * Obtain a Production CSID using a compliance certificate and request ID.
     */
    public function getProductionCsid(Certificate $complianceCert, string $requestId): Certificate
    {
        $this->client->setCertificate($complianceCert);
        $response = $this->client->requestProductionCsid($requestId);

        $productionCert = Certificate::fromApiResponse(
            $response,
            $complianceCert->getPrivateKey(),
            'production'
        );

        $this->certManager->store($productionCert);

        return $productionCert;
    }

    /**
     * Renew an existing Production CSID.
     */
    public function renewProductionCsid(Certificate $currentCert, string $otp, ?array $csrData = null): array
    {
        if (!$csrData) {
            $csrData = $this->csrGenerator->generate();
        }

        $this->client->setCertificate($currentCert);
        $response = $this->client->renewProductionCsid($csrData['csr'], $otp);

        $newCert = Certificate::fromApiResponse(
            $response,
            $csrData['private_key'],
            'production'
        );

        $this->certManager->store($newCert);

        return [
            'certificate' => $newCert,
            'request_id' => $response['requestID'] ?? null,
        ];
    }

    /**
     * Helper to build a sample invoice for compliance checks.
     */
    protected function buildSampleInvoice(array $sample, int $number): \Corecave\Zatca\Contracts\InvoiceInterface
    {
        $isSimplified = $sample['type'] === 'simplified';
        $isCredit = $sample['subtype'] === 'credit_note';
        $isDebit = $sample['subtype'] === 'debit_note';

        if ($isCredit) {
            $builder = InvoiceBuilder::creditNote($isSimplified);
            $builder->setOriginalInvoice('SME00001');
            $builder->setReason('Compliance test credit note');
        }
        elseif ($isDebit) {
            $builder = InvoiceBuilder::debitNote($isSimplified);
            $builder->setOriginalInvoice('SME00001');
            $builder->setReason('Compliance test debit note');
        }
        else {
            $builder = $isSimplified
                ?InvoiceBuilder::simplified()
                : InvoiceBuilder::standard();
        }

        $builder->setInvoiceNumber("SME0000{$number}");

        if (!$isSimplified) {
            $builder->setBuyer([
                'name' => 'Test Buyer Company',
                'vat_number' => '300000000000003',
                'registration_number' => '1234567890',
                'address' => [
                    'street' => 'Test Street',
                    'building' => '1234',
                    'city' => 'Riyadh',
                    'district' => 'Test District',
                    'postal_code' => '12345',
                    'country' => 'SA',
                ],
            ]);
        }

        $builder->addLineItem([
            'name' => 'Test Product',
            'quantity' => 1,
            'unit_price' => 100.00,
            'vat_category' => VatCategory::STANDARD,
            'vat_rate' => 15.00,
        ]);

        return $builder->build();
    }
}