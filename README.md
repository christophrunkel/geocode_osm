# TYPO3 Extension geocode_osm

This extension provides a Cron Task for geocoding tt_address records with OpenStreetMap Servers

## 1. Installation

Quick guide:
- Just install this extension - e.g. `composer require cri/geocode_osm`
- Create a Console Command ttaddress:geocodeosmin in Scheduler - 
- Provide Parameters like (tt_address page uid, E-Mail-Address, Server-URL, and Limits/Timeouts)
- Run the scheduler task
- That's all, you can view the geocoded records in the backend

## 2. Be careful

Certain Servers (f.e. nominatim have strict limitations on requests. It is not recommended to use this extension for a high number of records on public servers.
It is also recoomended to provide a valid e-mail adddress to not get blocked by the geocoding server

## 3. Version history

| geocode-osm | TYPO3     | PHP       | Support/Development                            |
|-------------|-----------|-----------|------------------------------------------------|
| 1.0.0       | 12.x-13.x | 8.3 - 8.x | Initial Upload                                 |

## 4. Support

This extensions comes with absolutely no warranty. 

Post issues or improvements on Github.

For further support or development contact dialog@christophrunkel.de or visit www.christophrunkel.de.


