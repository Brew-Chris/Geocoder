<?php

declare(strict_types=1);

/*
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Geocoder\Provider\LocationIQ;

use Geocoder\Collection;
use Geocoder\Exception\InvalidArgument;
use Geocoder\Exception\InvalidServerResponse;
use Geocoder\Exception\InvalidCredentials;
use Geocoder\Location;
use Geocoder\Model\AddressBuilder;
use Geocoder\Model\AddressCollection;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Provider\Provider;
use Http\Client\HttpClient;

/**
 * @author Srihari Thalla <srihari@unwiredlabs.com>
 */
final class LocationIQ extends AbstractHttpProvider implements Provider
{
    /**
     * @var string
     */
    const BASE_API_URL = 'https://{region}.locationiq.com/v1';

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var array
     */
    protected $regions = [
        'us1',
        'eu1',
    ];

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @param HttpClient $client an HTTP adapter
     * @param string     $apiKey an API key
     */
    public function __construct(HttpClient $client, string $apiKey, string $region = null)
    {
        if (empty($apiKey)) {
            throw new InvalidCredentials('No API key provided.');
        }

        $this->apiKey = $apiKey;
        if (null === $region) {
            $region = $this->regions[0];
        } elseif (true !== in_array($region, $this->regions, true)) {
            throw new InvalidArgument(sprintf('`region` must be null or one of `%s`', implode('`, `', $this->regions)));
        }
        $this->baseUrl = str_replace('{region}', $region, self::BASE_API_URL);

        parent::__construct($client);
    }

    /**
     * {@inheritdoc}
     */
    public function geocodeQuery(GeocodeQuery $query): Collection
    {
        $address = $query->getText();

        $url = sprintf($this->getGeocodeEndpointUrl(), urlencode($address), $query->getLimit());

        $content = $this->executeQuery($url, $query->getLocale());

        $doc = new \DOMDocument();
        if (!@$doc->loadXML($content) || null === $doc->getElementsByTagName('searchresults')->item(0)) {
            throw InvalidServerResponse::create($url);
        }

        $searchResult = $doc->getElementsByTagName('searchresults')->item(0);
        $places = $searchResult->getElementsByTagName('place');

        if (null === $places || 0 === $places->length) {
            return new AddressCollection([]);
        }

        $results = [];
        foreach ($places as $place) {
            $results[] = $this->xmlResultToArray($place, $place);
        }

        return new AddressCollection($results);
    }

    /**
     * {@inheritdoc}
     */
    public function reverseQuery(ReverseQuery $query): Collection
    {
        $coordinates = $query->getCoordinates();
        $longitude = $coordinates->getLongitude();
        $latitude = $coordinates->getLatitude();
        $url = sprintf($this->getReverseEndpointUrl(), $latitude, $longitude, $query->getData('zoom', 18));
        $content = $this->executeQuery($url, $query->getLocale());

        $doc = new \DOMDocument();
        if (!@$doc->loadXML($content) || $doc->getElementsByTagName('error')->length > 0) {
            return new AddressCollection([]);
        }

        $searchResult = $doc->getElementsByTagName('reversegeocode')->item(0);
        $addressParts = $searchResult->getElementsByTagName('addressparts')->item(0);
        $result = $searchResult->getElementsByTagName('result')->item(0);

        return new AddressCollection([$this->xmlResultToArray($result, $addressParts)]);
    }

    /**
     * @param \DOMElement $resultNode
     * @param \DOMElement $addressNode
     *
     * @return Location
     */
    private function xmlResultToArray(\DOMElement $resultNode, \DOMElement $addressNode): Location
    {
        $builder = new AddressBuilder($this->getName());

        foreach (['state', 'county'] as $i => $tagName) {
            if (null !== ($adminLevel = $this->getNodeValue($addressNode->getElementsByTagName($tagName)))) {
                $builder->addAdminLevel($i + 1, $adminLevel, '');
            }
        }

        // get the first postal-code when there are many
        $postalCode = $this->getNodeValue($addressNode->getElementsByTagName('postcode'));
        if (!empty($postalCode)) {
            $postalCode = current(explode(';', $postalCode));
        }
        $builder->setPostalCode($postalCode);
        $builder->setStreetName($this->getNodeValue($addressNode->getElementsByTagName('road')) ?: $this->getNodeValue($addressNode->getElementsByTagName('pedestrian')));
        $builder->setStreetNumber($this->getNodeValue($addressNode->getElementsByTagName('house_number')));
        $builder->setLocality($this->getNodeValue($addressNode->getElementsByTagName('city')));
        $builder->setSubLocality($this->getNodeValue($addressNode->getElementsByTagName('suburb')));
        $builder->setCountry($this->getNodeValue($addressNode->getElementsByTagName('country')));
        $builder->setCoordinates($resultNode->getAttribute('lat'), $resultNode->getAttribute('lon'));

        $countryCode = $this->getNodeValue($addressNode->getElementsByTagName('country_code'));
        if (!is_null($countryCode)) {
            $builder->setCountryCode(strtoupper($countryCode));
        }

        $boundsAttr = $resultNode->getAttribute('boundingbox');
        if ($boundsAttr) {
            $bounds = [];
            list($bounds['south'], $bounds['north'], $bounds['west'], $bounds['east']) = explode(',', $boundsAttr);
            $builder->setBounds($bounds['south'], $bounds['north'], $bounds['west'], $bounds['east']);
        }

        return $builder->build();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'locationiq';
    }

    /**
     * @param string      $url
     * @param string|null $locale
     *
     * @return string
     */
    private function executeQuery(string $url, string $locale = null): string
    {
        if (null !== $locale) {
            $url = sprintf('%s&accept-language=%s', $url, $locale);
        }

        return $this->getUrlContents($url);
    }

    private function getGeocodeEndpointUrl(): string
    {
        return $this->baseUrl.'/search.php?q=%s&format=xmlv1.1&addressdetails=1&normalizecity=1&limit=%d&key='.$this->apiKey;
    }

    private function getReverseEndpointUrl(): string
    {
        return $this->baseUrl.'/reverse.php?format=xmlv1.1&lat=%F&lon=%F&addressdetails=1&normalizecity=1&zoom=%d&key='.$this->apiKey;
    }

    private function getNodeValue(\DOMNodeList $element)
    {
        return $element->length ? $element->item(0)->nodeValue : null;
    }
}
