<?php
namespace verbb\shippy\carriers;

use Composer\InstalledVersions;
use Illuminate\Support\Arr;
use verbb\shippy\Shippy;
use verbb\shippy\exceptions\InvalidRequestException;
use verbb\shippy\helpers\Json;
use verbb\shippy\models\Address;
use verbb\shippy\models\HttpClient;
use verbb\shippy\models\Label;
use verbb\shippy\models\LabelResponse;
use verbb\shippy\models\Rate;
use verbb\shippy\models\RateResponse;
use verbb\shippy\models\Request;
use verbb\shippy\models\Response;
use verbb\shippy\models\Shipment;
use verbb\shippy\models\Tracking;
use verbb\shippy\models\TrackingDetail;
use verbb\shippy\models\TrackingResponse;

class UPS extends AbstractCarrier
{
    // Static Methods
    // =========================================================================

    public static function getName(): string
    {
        return 'UPS';
    }

    public static function getWeightUnit(Shipment $shipment): string
    {
        return ($shipment->getFrom()->getCountryCode() === 'US') ? 'lb' : 'kg';
    }

    public static function getDimensionUnit(Shipment $shipment): string
    {
        return ($shipment->getFrom()->getCountryCode() === 'US') ? 'in' : 'cm';
    }

    public static function getTrackingUrl(string $trackingNumber): ?string
    {
        return "https://wwwapps.ups.com/WebTracking/track?track=yes&trackNums={$trackingNumber}";
    }

    public static function getServiceCodes(): array
    {
        return [
            'US' => [ // United States
                '01' => 'UPS Next Day Air',
                '02' => 'UPS 2nd Day Air',
                '03' => 'UPS Ground',
                '07' => 'UPS Worldwide Express',
                '08' => 'UPS Worldwide Expedited',
                '11' => 'UPS Standard',
                '12' => 'UPS 3 Day Select',
                '13' => 'UPS Next Day Air Saver',
                '14' => 'UPS Next Day Air Early',
                '54' => 'UPS Worldwide Express Plus',
                '59' => 'UPS 2nd Day Air A.M.',
                '65' => 'UPS Worldwide Saver',
                '75' => 'UPS Heavy Goods',
            ],
            'CA' => [ // Canada
                '01' => 'UPS Express',
                '02' => 'UPS Expedited',
                '07' => 'UPS Worldwide Express',
                '08' => 'UPS Worldwide Expedited',
                '11' => 'UPS Standard',
                '12' => 'UPS 3 Day Select',
                '13' => 'UPS Express Saver',
                '14' => 'UPS Express Early',
                '54' => 'UPS Worldwide Express Plus',
                '65' => 'UPS Express Saver',
                '70' => 'UPS Access Point Economy',
            ],
            'EU' => [ // European Union
                '07' => 'UPS Express',
                '08' => 'UPS Expedited',
                '11' => 'UPS Standard',
                '54' => 'UPS Worldwide Express Plus',
                '65' => 'UPS Worldwide Saver',
                '70' => 'UPS Access Point Economy',

                '82' => 'UPS Today Standard',
                '83' => 'UPS Today Dedicated Courier',
                '84' => 'UPS Today Intercity',
                '85' => 'UPS Today Express',
                '86' => 'UPS Today Express Saver',
                '01' => 'UPS Next Day Air',
                '02' => 'UPS 2nd Day Air',
                '03' => 'UPS Ground',
                '14' => 'UPS Next Day Air Early',
            ],
            'PR' => [ // Puerto Rico
                '01' => 'UPS Next Day Air',
                '02' => 'UPS 2nd Day Air',
                '03' => 'UPS Ground',
                '07' => 'UPS Worldwide Express',
                '08' => 'UPS Worldwide Expedited',
                '14' => 'UPS Next Day Air Early',
                '54' => 'UPS Worldwide Express Plus',
                '65' => 'UPS Worldwide Saver',
            ],
            'MX' => [ // Mexico
                '07' => 'UPS Express',
                '08' => 'UPS Expedited',
                '11' => 'UPS Standard',
                '54' => 'UPS Worldwide Express Plus',
                '65' => 'UPS Worldwide Saver',
            ],
            'international' => [ // International
                '07' => 'UPS Worldwide Express',
                '08' => 'UPS Worldwide Expedited',
                '11' => 'UPS Standard',
                '54' => 'UPS Worldwide Express Plus',
                '65' => 'UPS Worldwide Saver',
            ],
        ];
    }


    // Properties
    // =========================================================================

    protected ?string $clientId = null;
    protected ?string $clientSecret = null;
    protected ?string $accountNumber = null;
    protected ?string $pickupType = '01';
    protected bool $includeInsurance = false;
    protected bool $addDeclaredValue = false;

    private array $pickupCodes = [
        '01' => 'Daily Pickup',
        '03' => 'Customer Counter',
        '06' => 'One Time Pickup',
        '07' => 'On Call Air',
        '19' => 'Letter Center',
        '20' => 'Air Service Center',
    ];


    // Public Methods
    // =========================================================================

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(?string $clientId): UPS
    {
        $this->clientId = $clientId;
        return $this;
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function setClientSecret(?string $clientSecret): UPS
    {
        $this->clientSecret = $clientSecret;
        return $this;
    }

    public function getAccountNumber(): ?string
    {
        return $this->accountNumber;
    }

    public function setAccountNumber(?string $accountNumber): UPS
    {
        $this->accountNumber = $accountNumber;
        return $this;
    }

    public function getPickupType(): ?string
    {
        return $this->pickupType;
    }

    public function setPickupType(?string $pickupType): UPS
    {
        $this->pickupType = $pickupType;
        return $this;
    }

    public function getIncludeInsurance(): bool
    {
        return $this->includeInsurance;
    }

    public function setIncludeInsurance(bool $includeInsurance): UPS
    {
        $this->includeInsurance = $includeInsurance;
        return $this;
    }

    public function getAddDeclaredValue(): bool
    {
        return $this->addDeclaredValue;
    }

    public function setAddDeclaredValue(bool $addDeclaredValue): UPS
    {
        $this->addDeclaredValue = $addDeclaredValue;
        return $this;
    }

    /**
     * @throws InvalidRequestException
     */
    public function getRates(Shipment $shipment): ?RateResponse
    {
        $this->validate('clientId', 'clientSecret');

        $payload = [
            'RateRequest' => [
                'PickupType' => [
                    'Code' => $this->getPickupType(),
                ],
                'Shipment' => [
                    'Shipper' => $this->getContact($shipment->getFrom()),
                    'ShipFrom' => $this->getContact($shipment->getFrom()),
                    'ShipTo' => $this->getContact($shipment->getTo()),
                    'NumOfPieces' => count($shipment->getPackages()),
                    'Package' => $this->getPackages($shipment),
                    'TaxInformationIndicator' => 'Y',
                ],
            ],
        ];

        // Use for negotiated rates, but not compulsary
        if ($this->accountNumber) {
            $payload['RateRequest']['Shipment']['Shipper']['ShipperNumber'] = $this->accountNumber;

            $payload['RateRequest']['Shipment']['ShipmentRatingOptions'] = [
                'NegotiatedRatesIndicator' => 'Y',
            ];

            $payload['RateRequest']['Shipment']['PaymentDetails'] = [
                'ShipmentCharge' => [
                    'Type' => '01',
                    'BillShipper' => [
                        'AccountNumber' => $this->accountNumber,
                    ],
                ],
            ];
        }

        $request = new Request([
            'endpoint' => 'api/rating/v1/Shop',
            'payload' => [
                'json' => $payload,
            ],
        ]);

        $data = $this->fetchRates($request, function(Response $response) {
            return $response->json();
        });

        $rates = [];

        foreach (Arr::get($data, 'RateResponse.RatedShipment', []) as $shippingRate) {
            // Negotiated rates will be different to regular rates, and we should also check for taxes included
            $rate = Arr::get($shippingRate, 'NegotiatedRateCharges.TotalChargesWithTaxes.MonetaryValue') ?? Arr::get($shippingRate, 'NegotiatedRateCharges.TotalCharge.MonetaryValue') ?? Arr::get($shippingRate, 'TotalChargesWithTaxes.MonetaryValue') ?? Arr::get($shippingRate, 'TotalCharges.MonetaryValue');

            // Convert the service code to a nicer description
            $serviceRegion = Arr::get(self::getServiceCodes(), $shipment->getFrom()->getCountryCode(), Arr::get(self::getServiceCodes(), 'international'));
            $serviceCode = Arr::get($shippingRate, 'Service.Code');
            $serviceName = Arr::get($serviceRegion, $serviceCode, '');

            // Sanity check in case a code can't be found
            if (!$serviceCode) {
                Shippy::debug('{name} Rate Request: Unable to find "Service.Code" for rate: {payload}', [
                    'name' => static::getName(),
                    'payload' => Json::encode($shippingRate),
                ]);

                continue;
            }

            $rates[] = new Rate([
                'carrier' => $this,
                'response' => $shippingRate,
                'serviceName' => $serviceName,
                'serviceCode' => $serviceCode,
                'rate' => $rate,
                'currency' => Arr::get($shippingRate, 'TotalCharges.CurrencyCode'),
                'deliveryDays' => Arr::get($shippingRate, 'GuaranteedDelivery.BusinessDaysInTransit'),
                'deliveryDateGuaranteed' => Arr::exists($shippingRate, 'GuaranteedDelivery'),
            ]);
        }

        return new RateResponse([
            'response' => $data,
            'rates' => $rates,
        ]);
    }

    /**
     * @throws InvalidRequestException
     */
    public function getTrackingStatus(array $trackingNumbers, array $options = []): ?TrackingResponse
    {
        $this->validate('clientId', 'clientSecret');

        $data = [];
        $tracking = [];

        foreach ($trackingNumbers as $trackingNumber) {
            $trackingNumber = str_replace(' ', '', $trackingNumber);

            $request = new Request([
                'method' => 'GET',
                'endpoint' => "api/track/v1/details/{$trackingNumber}",
            ]);

            $data = $this->fetchTracking($request, function(Response $response) {
                return $response->json();
            });

            // Handle the new response structure
            $shipments = Arr::get($data, 'trackResponse.shipment', []);

            foreach ($shipments as $shipment) {
                $packages = Arr::get($shipment, 'package', []);

                foreach ($packages as $package) {
                    // Extract delivery date if available
                    $deliveryDates = Arr::get($package, 'deliveryDate', []);
                    $estimatedDelivery = null;
                    if (!empty($deliveryDates)) {
                        // UPS delivery date array contains scheduling history:
                        // - SDD (Scheduled Delivery Date): Initial scheduled date
                        // - RDD (Rescheduled Delivery Date): Updated/rescheduled date
                        // - DEL (Delivered): Actual delivery date
                        // Take the last entry as it represents the most current/accurate date
                        $latestDeliveryDate = end($deliveryDates);
                        $estimatedDelivery = Arr::get($latestDeliveryDate, 'date');
                    }

                    // Parse activity/tracking details
                    $activities = Arr::get($package, 'activity', []);

                    // get current status from the latest (first) activity
                    $status = $this->_mapTrackingStatus(Arr::get($activities[0], 'status.type', ''));
                    $statusDetail = Arr::get($activities[0], 'status.description', '');

                    $details = array_map(function($activity) {
                        $location = '';
                        $address = Arr::get($activity, 'location.address');
                        if ($address) {
                            $locationParts = array_filter([
                                Arr::get($address, 'city'),
                                Arr::get($address, 'stateProvince'),
                                Arr::get($address, 'countryCode'),
                            ]);
                            $location = implode(', ', $locationParts);
                        }

                        $description = Arr::get($activity, 'status.description', '');

                        // Format date and time
                        $date = Arr::get($activity, 'date', '');
                        $time = Arr::get($activity, 'time', '');
                        $dateTime = '';
                        if ($date && $time) {
                            // Convert YYYYMMDD and HHMMSS to readable format
                            $formattedDate = date('Y-m-d', strtotime($date));
                            $formattedTime = date('H:i:s', strtotime($time));
                            $dateTime = $formattedDate . ' ' . $formattedTime;
                        }

                        return new TrackingDetail([
                            'location' => $location,
                            'description' => $description,
                            'date' => $dateTime,
                            'status' => $this->_mapTrackingStatus(Arr::get($activity, 'status.type', '')),
                            'statusDetail' => Arr::get($activity, 'status.description', '')
                        ]);
                    }, $activities);

                    $tracking[] = new Tracking([
                        'carrier' => $this,
                        'response' => $data,
                        'trackingNumber' => Arr::get($package, 'trackingNumber', $trackingNumber),
                        'status' => $status,
                        'statusDetail' => $statusDetail,
                        'estimatedDelivery' => $estimatedDelivery,
                        'details' => $details,
                        'signedBy' => Arr::get($package, 'deliveryInformation.receivedBy', null),
                        'weight' => Arr::get($package, 'weight.weight', null),
                        'weightUnit' => Arr::get($package, 'weight.unitOfMeasurement', null),
                    ]);
                }
            }
        }

        return new TrackingResponse([
            'response' => $data,
            'tracking' => $tracking,
        ]);
    }

    /**
     * @throws InvalidRequestException
     */
    public function getLabels(Shipment $shipment, Rate $rate, array $options = []): ?LabelResponse
    {
        $this->validate('clientId', 'clientSecret', 'accountNumber');

        $payload = [
            'ShipmentRequest' => [
                'Shipment' => [
                    'Shipper' => $this->getContact($shipment->getFrom()),
                    'ShipFrom' => $this->getContact($shipment->getFrom()),
                    'ShipTo' => $this->getContact($shipment->getTo()),
                    'NumOfPieces' => count($shipment->getPackages()),
                    'Package' => array_map(function($package) {
                        // Rates uses `PackagingType`, labels uses `Packaging`
                        Arr::set($package, 'Packaging', Arr::pull($package, 'PackagingType'));

                        return $package;
                    }, $this->getPackages($shipment)),
                    'Service' => [
                        'Code' => $rate->getServiceCode(),
                    ],
                    'PaymentInformation' => [
                        'ShipmentCharge' => [
                            'Type' => '01',
                            'BillShipper' => [
                                'AccountNumber' => $this->accountNumber,
                            ],
                        ],
                    ],
                ],

                'LabelSpecification' => array_replace_recursive([
                    'LabelImageFormat' => [
                        'Code' => 'GIF',
                    ],
                ], $options),
            ],
        ];

        $payload['ShipmentRequest']['Shipment']['Shipper']['ShipperNumber'] = $this->accountNumber;

        $request = new Request([
            'endpoint' => 'api/shipments/v1/ship',
            'payload' => [
                'json' => $payload,
            ],
        ]);

        // Custom parsing for multipart response
        $data = $this->fetchLabels($request, function(Response $response) {
            return $response->json();
        });

        $shipmentId = Arr::get($data, 'ShipmentResponse.ShipmentResults.ShipmentIdentificationNumber', '');

        $labels = [];

        if ($shipmentId) {
            $labels[] = new Label([
                'carrier' => $this,
                'response' => $data,
                'rate' => $rate,
                'trackingNumber' => Arr::get($data, 'ShipmentResponse.ShipmentResults.PackageResults.TrackingNumber', ''),
                'labelId' => $shipmentId,
                'labelData' => Arr::get($data, 'ShipmentResponse.ShipmentResults.PackageResults.ShippingLabel.GraphicImage', ''),
                'labelMime' => 'image/gif',
            ]);
        }

        return new LabelResponse([
            'response' => $data,
            'labels' => $labels,
        ]);
    }

    public function getHttpClient(): HttpClient
    {
        if ($this->isProduction()) {
            $url = 'https://onlinetools.ups.com/';
        } else {
            $url = 'https://wwwcie.ups.com/';
        }

        $transId = bin2hex(random_bytes(10));
        $transactionSrc = 'Shippy ' . InstalledVersions::getPrettyVersion('verbb/shippy');

        // Fetch an access token first
        $authResponse = Json::decode((string)(new HttpClient())
            ->request('POST', $url . 'security/v1/oauth/token', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'x-merchant-id' => $this->clientId,
                ],
                'auth' => [$this->clientId, $this->clientSecret],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                ],
            ])->getBody());

        return new HttpClient([
            'base_uri' => $url,
            'headers' => [
                'Authorization' => 'Bearer ' . $authResponse['access_token'] ?? '',
                'Content-Type' => 'application/json',
                'transId' => $transId,
                'transactionSrc' => $transactionSrc,
            ],
        ]);
    }


    // Protected Methods
    // =========================================================================

    protected function getAddress(Address $address): array
    {
        $object = [
            'AddressLine' => array_filter([
                $address->getStreet1(),
                $address->getStreet2(),
                $address->getStreet3(),
            ]),
            'City' => $address->getCity(),
            'StateProvinceCode' => $address->getStateProvince(),
            'PostalCode' => $address->getPostalCode(),
            'CountryCode' => $address->getCountryCode(),
        ];

        if ($address->isResidential()) {
            $object['ResidentialAddressIndicator'] = true;
        }

        return $object;
    }

    protected function getContact(Address $address): array
    {
        $contact = [
            'Name' => $address->getFullName(),
            'AttentionName' => $address->getFullName(),
            'Address' => $this->getAddress($address),
        ];

        if ($phone = $address->getPhone()) {
            $contact['Phone']['Number'] = $phone;
        }

        if ($email = $address->getEmail()) {
            $contact['EMailAddress'] = $email;
        }

        return $contact;
    }

    protected function getPackages(Shipment $shipment): array
    {
        return array_map(function($package) use ($shipment) {
            $providerPackage = [
                'PackagingType' => [
                    'Code' => '02',
                ],
                'Dimensions' => [
                    'UnitOfMeasurement' => [
                        'Code' => $this->_getDimensionUnit($shipment),
                    ],
                    'Length' => $package->getLength(),
                    'Width' => $package->getWidth(),
                    'Height' => $package->getHeight(),
                ],
                'PackageWeight' => [
                    'UnitOfMeasurement' => [
                        'Code' => $this->_getWeightUnit($shipment),
                    ],
                    'Weight' => $package->getWeight(),
                ],
            ];

            if ($this->getAddDeclaredValue()) {
                $providerPackage['PackageServiceOptions'] = [
                    'DeclaredValue' => [
                        'CurrencyCode' => $shipment->getCurrency(),
                        'MonetaryValue' => (string)$package->getPrice(),
                    ],
                ];
            }

            return $providerPackage;
        }, $shipment->getPackages());
    }


    // Private Methods
    // =========================================================================

    private function _mapTrackingStatus(string $status): string
    {
        return match ($status) {
            'I', 'P', 'M' => Tracking::STATUS_IN_TRANSIT,
            'D' => Tracking::STATUS_DELIVERED,
            'X' => Tracking::STATUS_ERROR,
            default => Tracking::STATUS_UNKNOWN,
        };
    }

    private function _getWeightUnit(Shipment $shipment): string
    {
        $unit = self::getWeightUnit($shipment);

        return match ($unit) {
            'lb' => 'LBS',
            'kg' => 'KGS',
        };
    }

    private function _getDimensionUnit(Shipment $shipment): string
    {
        $unit = self::getDimensionUnit($shipment);

        return match ($unit) {
            'in' => 'IN',
            'cm' => 'CM',
        };
    }
}
