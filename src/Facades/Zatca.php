<?php

namespace Corecave\Zatca\Facades;

use Corecave\Zatca\Contracts\InvoiceInterface;
use Corecave\Zatca\Invoice\InvoiceBuilder;
use Corecave\Zatca\ZatcaManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static InvoiceBuilder invoice()
 * @method static ZatcaManager setCsrConfig(array $config)
 * @method static array getCsrConfig()
 * @method static ZatcaManager setSellerConfig(array $config)
 * @method static array getSellerConfig()
 * @method static \Corecave\Zatca\Certificate\CertificateManager certificate()
 * @method static \Corecave\Zatca\Certificate\CsrGenerator csr()
 * @method static array generateCsr(array $params = [], ?string $environment = null)
 * @method static array runCompliance(string $otp, array $options = [])
 * @method static array runComplianceChecks(?\Corecave\Zatca\Certificate\Certificate $certificate = null, array $options = [])
 * @method static array requestProductionCsid(?string $requestId = null, ?\Corecave\Zatca\Certificate\Certificate $complianceCert = null, array $options = [])
 * @method static array renewProductionCsid(string $otp, array $options = [])
 * @method static array cleanup(array $options = [])
 * @method static \Corecave\Zatca\Client\ZatcaClient client()
 * @method static \Corecave\Zatca\Results\ReportResult report(InvoiceInterface $invoice)
 * @method static \Corecave\Zatca\Results\ClearanceResult clear(InvoiceInterface $invoice)
 * @method static \Corecave\Zatca\Results\ProcessResult process(InvoiceInterface $invoice)
 * @method static \Corecave\Zatca\Results\ComplianceResult compliance()
 * @method static string generateQrCode(InvoiceInterface $invoice, string $signature, string $publicKey)
 * @method static string generateXml(InvoiceInterface $invoice)
 * @method static string signXml(string $xml)
 * @method static bool validateXml(string $xml)
 *
 * @see ZatcaManager
 */
class Zatca extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'zatca';
    }
}
