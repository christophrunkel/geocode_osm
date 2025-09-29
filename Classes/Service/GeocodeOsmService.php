<?php

declare(strict_types=1);

namespace Cri\GeocodeOsm\Service;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class GeocodeOsmService implements SingletonInterface
{
    /** @var int Cachezeit in Sekunden (90 Tage) */
    protected int $cacheTime = 7776000;
    
    /** @var string Basis-URL Nominatim */
    protected string $nominatimBase = 'https://nominatim.openstreetmap.org';
    
    /** @var string App-Name für User-Agent */
    protected string $appName = 'TYPO3-GeocodeOsm';
    
    /** @var int Max number of results */
    protected int $maxResults = 100;
    
    /** @var string Kontakt-Email für User-Agent (erforderlich) */
    protected string $email = 'info@biwe-bbq.de';
    
    /** @var int Wartezeit pro Adresse in Mikrosekunden (2 Sekunden) */
    protected int $throttleMicroseconds = 2_000_000;
    
    protected RequestFactory $requestFactory;
    
    
    /**
     * Parameterloser Konstruktor für GeneralUtility::makeInstance(...)
     */
    public function __construct()
    {
        $this->requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
    }
    
    /** Fluent Setter */
    public function setPageUid($pageUid): self
    {
        $this->pageUid = ($pageUid === null || $pageUid === '') ? null : (int)$pageUid;
        return $this;
    }
    
    public function setEmail($email): self
    {
        $this->contactEmail = (string)$email;
        return $this;
    }
    
    public function setNominatimBase($base): self
    {
        $this->nominatimBase = rtrim((string)$base, '/') ?: $this->nominatimBase;
        return $this;
    }
    
    public function setThrottleMicroseconds($us): self
    {
        $val = (int)$us;
        $this->throttleMicroseconds = $val >= 0 ? $val : $this->throttleMicroseconds;
        return $this;
    }
    
    public function setMaxResults($max): self
    {
        $val = (int)$max;
        if ($val > 0) {
            $this->maxResults = $val;
        }
        return $this;
    }
    
    /**
     * Geocodiert alle Datensätze in tt_address, denen Lat/Lon fehlt.
     *
     * @param string $addWhereClause Zusätzliche WHERE-Bedingung (ohne führendes AND/OR)
     */
    public function calculateCoordinatesForAllRecordsInTable(): int
    {
        $tableName     = 'tt_address';
        $latitudeField = 'latitude';
        $longitudeField = 'longitude';
        $streetField   = 'address';
        $zipField      = 'zip';
        $cityField     = 'city';
        $countryField  = 'country';
        
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tableName);
        $qb = $connection->createQueryBuilder();
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        
        $qb->select('*')
        ->from($tableName)
        ->where(
            $qb->expr()->or(
                $qb->expr()->isNull($latitudeField),
                $qb->expr()->eq($latitudeField, $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq($latitudeField, 0.0),
                $qb->expr()->isNull($longitudeField),
                $qb->expr()->eq($longitudeField, $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq($longitudeField, 0.0)
                )
            );
        
            
            if (!empty($addWhereClause)) {
                $qb->andWhere(QueryHelper::stripLogicalOperatorPrefix($addWhereClause));
            }
            
            if ($this->pageUid !== null) {
                $qb->andWhere(
                    $qb->expr()->eq('pid', $qb->createNamedParameter($this->pageUid, Connection::PARAM_INT))
                    );
            }
            
            $qb->setMaxResults($this->maxResults);
            
            
            
            
            $records = $qb->executeQuery()->fetchAllAssociative();
            $total = \count($records);
            if ($total === 0) {
                return 0;
            }
            
            foreach ($records as $idx => $record) {
                $street  = (string)($record[$streetField] ?? '');
                $zip     = (string)($record[$zipField] ?? '');
                $city    = (string)($record[$cityField] ?? '');
                $country = (string)($record[$countryField] ?? '');
                
                // Mindestens irgendein Adressbestandteil muss vorhanden sein
                if ($street === '' && $zip === '' && $city === '' && $country === '') {
                    continue;
                }
                
                $coords = $this->getCoordinatesForAddress($street, $zip, $city, $country);
                
                if (!empty($coords)) {
                    $connection->update(
                        $tableName,
                        [
                            $latitudeField  => $coords['latitude'],
                            $longitudeField => $coords['longitude'],
                        ],
                        ['uid' => (int)$record['uid']]
                        );
                }
                
                // 2s Pause zwischen Requests (außer nach dem letzten)
                if ($this->throttleMicroseconds > 0 && $idx < $total - 1) {
                    usleep($this->throttleMicroseconds);
                }
            }
            
            return $total;
    }
    
    /**
     * Holt Koordinaten per Nominatim und cached das Ergebnis.
     *
     * @return array{latitude: float, longitude: float}|array{}
     */
    public function getCoordinatesForAddress($street = null, $zip = null, $city = null, $country = ''): array
    {
        $addressParts = [];
        foreach ([$street, trim(($zip ?? '') . ' ' . ($city ?? '')), $country] as $addressPart) {
            if (!empty($addressPart)) {
                $addressParts[] = trim((string)$addressPart);
            }
        }
        $address = ltrim(implode(', ', $addressParts), ', ');
        if ($address === '') {
            return [];
        }
        
        $cache = $this->initializeCache();
        $cacheKey = 'geocode-osm-' . strtolower(str_replace(' ', '-', preg_replace('/[^0-9a-zA-Z ]/m', '', $address)));
        
        if ($cache->has($cacheKey)) {
            /** @var array{latitude: float, longitude: float} $cached */
            $cached = $cache->get($cacheKey);
            return $cached;
        }
        
        // Strukturierte Query-Parameter an Nominatim
        $query = [
            'format'        => 'jsonv2',
            'limit'         => '1',
            'addressdetails'=> '0',
            'accept-language' => 'de',
        ];
        
        // Bevorzugt strukturierte Suche, fällt zurück auf q=
        $hasStructured = false;
        if (!empty($street)) {
            $query['street'] = $street;
            $hasStructured = true;
        }
        if (!empty($zip)) {
            $query['postalcode'] = $zip;
            $hasStructured = true;
        }
        if (!empty($city)) {
            $query['city'] = $city;
            $hasStructured = true;
        }
        if (!empty($country)) {
            $query['country'] = $country;
            $hasStructured = true;
        }
        if (!$hasStructured) {
            $query['q'] = $address;
        }
        
        $url = $this->nominatimBase . '/search?' . http_build_query($query);
        
        $result = $this->getApiCallResult($url);
        if (empty($result)) {
            return [];
        }
        
        // Nominatim liefert Array von Treffern
        $first = $result[0] ?? null;
        if (!is_array($first) || !isset($first['lat'], $first['lon'])) {
            return [];
        }
        
        $coords = [
            'latitude'  => (float)$first['lat'],
            'longitude' => (float)$first['lon'],
        ];
        
        $cache->set($cacheKey, $coords, [], $this->cacheTime);
        return $coords;
    }
    
    /**
     * Führt HTTP-GET aus, behandelt Rate-Limits/Fehler und liefert JSON-decoded Array.
     */
    protected function getApiCallResult(string $url): array
    {
        // Pflicht: sinnvoller User-Agent inkl. Kontakt
        $userAgent = trim(sprintf('%s (%s)', $this->appName, $this->email ?: 'contact-not-set@example.com'));
        
        try {
            /** @var ResponseInterface $response */
            $response = $this->requestFactory->request($url, 'GET', [
                'headers' => [
                    'User-Agent'     => $userAgent,
                    'Accept'         => 'application/json',
                    'Accept-Language'=> 'de',
                ],
                'allow_redirects' => true,
                'timeout' => 15,
            ]);
        } catch (\Throwable $e) {
            // Netzwerk-/Transportfehler
            return [];
        }
        
        $status = $response->getStatusCode();
        if ($status === 429) {
            // Zu viele Anfragen – wir liefern leer zurück.
            return [];
        }
        if ($status < 200 || $status >= 300) {
            return [];
        }
        
        $body = (string)$response->getBody();
        if ($body === '') {
            return [];
        }
        
        $decoded = json_decode($body, true);
        return \is_array($decoded) ? $decoded : [];
    }
    
    /**
     * Cache initialisieren.
     */
    protected function initializeCache(string $name = 'ttaddress_geocoding'): FrontendInterface
    {
        try {
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            return $cacheManager->getCache($name);
        } catch (NoSuchCacheException $e) {
            throw new \RuntimeException('Unable to load Cache!', 1548785854);
        }
    }
}
