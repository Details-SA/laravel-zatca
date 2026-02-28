<?php

namespace Corecave\Zatca\DTO;

class OnboardingProfile
{
    /**
     * The organization or company legal name.
     */
    public string $organization;

    /**
     * The organization unit (branch/department name).
     */
    public string $organizationUnit;

    /**
     * Common name for the certificate.
     */
    public string $commonName;

    /**
     * VAT registration number (15 digits).
     */
    public string $vatNumber;

    /**
     * Invoice types supported:
     * '1000' = B2C only (simplified invoices)
     * '0100' = B2B only (standard invoices)
     * '1100' = Both B2B and B2C
     */
    public string $invoiceTypes = '1100';

    /**
     * EGS setup location (can be just city or array of address details).
     * @var string|array
     */
    public $location = [];

    /**
     * Business category (e.g., 'Technology', 'Retail').
     */
    public string $businessCategory = 'Technology';

    /**
     * Custom serial number. If null, a default one will be generated.
     */
    public ?string $serialNumber = null;

    /**
     * Environment context for generation ('sandbox', 'simulation', 'production').
     */
    public string $environment = 'sandbox';

    public function __construct(
        string $organization,
        string $organizationUnit,
        string $commonName,
        string $vatNumber,
        string $invoiceTypes = '1100',
        $location = [],
        string $businessCategory = 'Technology',
        ?string $serialNumber = null,
        string $environment = 'sandbox'
        )
    {
        $this->organization = $organization;
        $this->organizationUnit = $organizationUnit;
        $this->commonName = $commonName;
        $this->vatNumber = $vatNumber;
        $this->invoiceTypes = $invoiceTypes;
        $this->location = $location;
        $this->businessCategory = $businessCategory;
        $this->serialNumber = $serialNumber;
        $this->environment = $environment;
    }

    /**
     * Convert the profile into the configuration array expected by CsrGenerator.
     */
    public function toArray(): array
    {
        return [
            'country' => 'SA',
            'organization' => $this->organization,
            'organization_unit' => $this->organizationUnit,
            'common_name' => $this->commonName,
            'vat_number' => $this->vatNumber,
            'invoice_types' => $this->invoiceTypes,
            'location' => $this->location,
            'business_category' => $this->businessCategory,
            'serial_number' => $this->serialNumber,
        ];
    }
}