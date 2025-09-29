<?php

declare(strict_types=1);

namespace Cri\GeocodeOsm\Command;

/*
 * This file is part of the "tt_address" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */
use Cri\GeocodeOsm\Service\GeocodeOsmService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Command for geocoding coordinates
 */
class GeocodeCommand extends Command
{
    /**
     * Defines the allowed options for this command
     *
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setDescription('Geocode tt_address records');
        $this->addArgument('pageUid', InputArgument::OPTIONAL, 'Page Id with existing addresses', 0);
        $this->addArgument('email', InputArgument::OPTIONAL, 'E-Mail Address required for Not', 'info@yourdomain.com');
        $this->addArgument('nominatimBase', InputArgument::OPTIONAL, 'nominatimBase - Geocode URL', ' https://nominatim.openstreetmap.org');
        $this->addArgument('throttleMicroseconds', InputArgument::OPTIONAL, 'Timeout in Microseconds', 2_000_000);
        $this->addArgument('maxResults', InputArgument::OPTIONAL, 'Maximum of Addresses per Run', 100);
        
    }

    /**
     * Geocode all records
     *
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $geocodeOsmService = GeneralUtility::makeInstance(GeocodeOsmService::class);
        
        $geocodeOsmService
        ->setPageUid($input->getArgument('pageUid'))
        ->setEmail($input->getArgument('email'))
        ->setNominatimBase($input->getArgument('nominatimBase'))
        ->setThrottleMicroseconds($input->getArgument('throttleMicroseconds'))
        ->setMaxResults($input->getArgument('maxResults'));
        
        $geocodeOsmService->calculateCoordinatesForAllRecordsInTable();
        return 0;
    }

 
}

