<?php

namespace Nominatim;

require_once(CONST_BasePath.'/lib/Result.php');

class ReverseGeocode
{
    protected $oDB;
    protected $iMaxRank = 28;


    public function __construct(&$oDB)
    {
        $this->oDB =& $oDB;
    }


    public function setZoom($iZoom)
    {
        // Zoom to rank, this could probably be calculated but a lookup gives fine control
        $aZoomRank = array(
                      0 => 2, // Continent / Sea
                      1 => 2,
                      2 => 2,
                      3 => 4, // Country
                      4 => 4,
                      5 => 8, // State
                      6 => 10, // Region
                      7 => 10,
                      8 => 12, // County
                      9 => 12,
                      10 => 17, // City
                      11 => 17,
                      12 => 18, // Town / Village
                      13 => 18,
                      14 => 22, // Suburb
                      15 => 22,
                      16 => 26, // Street, TODO: major street?
                      17 => 26,
                      18 => 30, // or >, Building
                      19 => 30, // or >, Building
                     );
        $this->iMaxRank = (isset($iZoom) && isset($aZoomRank[$iZoom]))?$aZoomRank[$iZoom]:28;
    }

    /**
     * Find the closest interpolation with the given search diameter.
     *
     * @param string  $sPointSQL      Reverse geocoding point as SQL
     * @param float   $fSearchDiam    Search diameter
     * @param integer $iParentPlaceID Id of parent object
     *
     * @return Record of the interpolation or null.
     */
    protected function lookupInterpolation($sPointSQL, $fSearchDiam, $iParentPlaceID = null)
    {
        $sSQL = 'SELECT place_id, parent_place_id, 30 as rank_search,';
        $sSQL .= '  ST_LineLocatePoint(linegeo,'.$sPointSQL.') as fraction,';
        $sSQL .= '  startnumber, endnumber, interpolationtype,';
        $sSQL .= '  ST_Distance(linegeo,'.$sPointSQL.') as distance';
        $sSQL .= ' FROM location_property_osmline';
        $sSQL .= ' WHERE ST_DWithin('.$sPointSQL.', linegeo, '.$fSearchDiam.')';
        $sSQL .= ' and indexed_status = 0 and startnumber is not NULL ';
        if (isset($iParentPlaceID)) {
            $sSQL .= ' and parent_place_id = '.$iParentPlaceID;
        }
        $sSQL .= ' ORDER BY distance ASC limit 1';

        return chksql(
            $this->oDB->getRow($sSQL),
            'Could not determine closest housenumber on an osm interpolation line.'
        );
    }

    protected function polygonFunctions($sPointSQL, $iMaxRank)
    {
        // starts the nopolygonFound function if no polygon is found with the lookupPolygon function
        $oResult = null;

        $aPlace = $this->lookupPolygon($sPointSQL, $iMaxRank);
        if ($aPlace) {
            $oResult = new Result($aPlace['place_id']);
        // if no polygon which contains the searchpoint is found,
        // the noPolygonFound function searches in the country_osm_grid table for a polygon
        } elseif (!$aPlace && $iMaxRank > 4) {
            $aPlace = $this->noPolygonFound($sPointSQL, $iMaxRank);
            if ($aPlace) {
                $oResult = new Result($aPlace['place_id']);
            }
        }
        return $oResult;
    }

    protected function noPolygonFound($sPointSQL, $iMaxRank)
    {
        // searches for polygon in table country_osm_grid which contains the searchpoint
        // and searches for the nearest place node to the searchpoint in this polygon
        $sSQL = 'SELECT country_code FROM country_osm_grid';
        $sSQL .= ' WHERE ST_CONTAINS (geometry, '.$sPointSQL.') limit 1';

        $aPoly = chksql(
            $this->oDB->getRow($sSQL),
            'Could not determine polygon containing the point.'
        );
        if ($aPoly) {
            $sCountryCode = $aPoly['country_code'];

            // look for place nodes with the given country code
            $sSQL = 'SELECT place_id FROM';
            $sSQL .= ' (SELECT place_id, rank_search,';
            $sSQL .= '         ST_distance('.$sPointSQL.', geometry) as distance';
            $sSQL .= ' FROM placex';
            $sSQL .= ' WHERE osm_type = \'N\'';
            $sSQL .= ' AND country_code = \''.$sCountryCode.'\'';
            $sSQL .= ' AND rank_search between 5 and ' .min(25, $iMaxRank);
            $sSQL .= ' AND class = \'place\' AND type != \'postcode\'';
            $sSQL .= ' AND name IS NOT NULL ';
            $sSQL .= ' and indexed_status = 0 and linked_place_id is null';
            $sSQL .= ' AND ST_DWithin('.$sPointSQL.', geometry, 5.0)) p ';
            $sSQL .= 'WHERE distance <= reverse_place_diameter(rank_search)';
            $sSQL .= ' ORDER BY rank_search DESC, distance ASC';
            $sSQL .= ' LIMIT 1';

            if (CONST_Debug) var_dump($sSQL);
            $aPlacNode = chksql(
                $this->oDB->getRow($sSQL),
                'Could not determine place node.'
            );
            if ($aPlacNode) {
                return $aPlacNode;
            }

            // still nothing, then return the country object
            $sSQL = 'SELECT place_id, ST_distance('.$sPointSQL.', centroid) as distance';
            $sSQL .= ' FROM placex';
            $sSQL .= ' WHERE country_code = \''.$sCountryCode.'\'';
            $sSQL .= ' AND rank_search = 4 AND rank_address = 4';
            $sSQL .= ' AND class in (\'boundary\',  \'place\')';
            $sSQL .= ' ORDER BY distance ASC';
            $sSQL .= ' LIMIT 1';

            if (CONST_Debug) var_dump($sSQL);
            $aPlacNode = chksql(
                $this->oDB->getRow($sSQL),
                'Could not determine place node.'
            );
            if ($aPlacNode) {
                return $aPlacNode;
            }
        }
    }

    protected function lookupPolygon($sPointSQL, $iMaxRank)
    {
        // searches for polygon where the searchpoint is within
        // if a polygon is found, placenodes with a higher rank are searched inside the polygon

        // polygon search begins at suburb-level
        if ($iMaxRank > 25) $iMaxRank = 25;
        // no polygon search over country-level
        if ($iMaxRank < 5) $iMaxRank = 5;
        // search for polygon
        $sSQL = 'SELECT place_id, parent_place_id, rank_address, rank_search FROM';
        $sSQL .= '(select place_id, parent_place_id, rank_address, rank_search, country_code, geometry';
        $sSQL .= ' FROM placex';
        $sSQL .= ' WHERE ST_GeometryType(geometry) in (\'ST_Polygon\', \'ST_MultiPolygon\')';
        $sSQL .= ' AND rank_address Between 5 AND ' .$iMaxRank;
        $sSQL .= ' AND geometry && '.$sPointSQL;
        $sSQL .= ' AND type != \'postcode\' ';
        $sSQL .= ' AND name is not null';
        $sSQL .= ' AND indexed_status = 0 and linked_place_id is null';
        $sSQL .= ' ORDER BY rank_address DESC LIMIT 50 ) as a';
        $sSQL .= ' WHERE ST_CONTAINS(geometry, '.$sPointSQL.' )';
        $sSQL .= ' ORDER BY rank_address DESC LIMIT 1';

        $aPoly = chksql(
            $this->oDB->getRow($sSQL),
            'Could not determine polygon containing the point.'
        );
        if ($aPoly) {
        // if a polygon is found, search for placenodes begins ...
            $iParentPlaceID = $aPoly['parent_place_id'];
            $iRankAddress = $aPoly['rank_address'];
            $iRankSearch = $aPoly['rank_search'];
            $iPlaceID = $aPoly['place_id'];

            if ($iRankAddress != $iMaxRank) {
                $sSQL = 'SELECT place_id FROM ';
                $sSQL .= '(SELECT place_id, rank_search, country_code, geometry,';
                $sSQL .= ' ST_distance('.$sPointSQL.', geometry) as distance';
                $sSQL .= ' FROM placex';
                $sSQL .= ' WHERE osm_type = \'N\'';
                // using rank_search because of a better differentiation
                // for place nodes at rank_address 16
                $sSQL .= ' AND rank_search > '.$iRankSearch;
                $sSQL .= ' AND rank_search <= '.$iMaxRank;
                $sSQL .= ' AND class = \'place\'';
                $sSQL .= ' AND type != \'postcode\'';
                $sSQL .= ' AND name IS NOT NULL ';
                $sSQL .= ' AND indexed_status = 0 AND linked_place_id is null';
                $sSQL .= ' AND ST_DWithin('.$sPointSQL.', geometry, reverse_place_diameter('.$iRankSearch.'::smallint))';
                $sSQL .= ' ORDER BY distance ASC,';
                $sSQL .= ' rank_address DESC';
                $sSQL .= ' limit 500) as a';
                $sSQL .= ' WHERE ST_CONTAINS((SELECT geometry FROM placex WHERE place_id = '.$iPlaceID.'), geometry )';
                $sSQL .= ' AND distance <= reverse_place_diameter(rank_search)';
                $sSQL .= ' ORDER BY distance ASC, rank_search DESC';
                $sSQL .= ' LIMIT 1';

                if (CONST_Debug) var_dump($sSQL);
                $aPlacNode = chksql(
                    $this->oDB->getRow($sSQL),
                    'Could not determine place node.'
                );
                if ($aPlacNode) {
                    return $aPlacNode;
                }
            }
        }
        return $aPoly;
    }


    public function lookup($fLat, $fLon, $bDoInterpolation = true)
    {
        return $this->lookupPoint(
            'ST_SetSRID(ST_Point('.$fLon.','.$fLat.'),4326)',
            $bDoInterpolation
        );
    }

    public function lookupPoint($sPointSQL, $bDoInterpolation = true)
    {
        // starts if the search is on POI or street level,
        // searches for the nearest POI or street,
        // if a street is found and a POI is searched for,
        // the nearest POI which the found street is a parent of is choosen.
        $iMaxRank = $this->iMaxRank;

        // Find the nearest point
        $fSearchDiam = 0.006;
        $oResult = null;
        $aPlace = null;
        $fMaxAreaDistance = 1;
        $bIsTigerStreet = false;

        // for POI or street level
        if ($iMaxRank >= 26) {
            $sSQL = 'select place_id,parent_place_id,rank_address,country_code,';
            $sSQL .= 'CASE WHEN ST_GeometryType(geometry) in (\'ST_Polygon\',\'ST_MultiPolygon\') THEN ST_distance('.$sPointSQL.', centroid)';
            $sSQL .= ' ELSE ST_distance('.$sPointSQL.', geometry) ';
            $sSQL .= ' END as distance';
            $sSQL .= ' FROM ';
            $sSQL .= ' placex';
            $sSQL .= '   WHERE ST_DWithin('.$sPointSQL.', geometry, '.$fSearchDiam.')';
            $sSQL .= '   AND';
            // only streets
            if ($iMaxRank == 26) {
                $sSQL .= ' rank_address = 26';
            } else {
                $sSQL .= ' rank_address between 26 and '.$iMaxRank;
            }
            $sSQL .= ' and (name is not null or housenumber is not null';
            $sSQL .= ' or rank_address between 26 and 27)';
            $sSQL .= ' and class not in (\'railway\',\'tunnel\',\'bridge\',\'man_made\')';
            $sSQL .= ' and indexed_status = 0 and linked_place_id is null';
            $sSQL .= ' and (ST_GeometryType(geometry) not in (\'ST_Polygon\',\'ST_MultiPolygon\') ';
            $sSQL .= ' OR ST_DWithin('.$sPointSQL.', centroid, '.$fSearchDiam.'))';
            $sSQL .= ' ORDER BY distance ASC limit 1';
            if (CONST_Debug) var_dump($sSQL);
            $aPlace = chksql(
                $this->oDB->getRow($sSQL),
                'Could not determine closest place.'
            );

            if ($aPlace) {
                $iDistance = $aPlace['distance'];
                $iPlaceID = $aPlace['place_id'];
                $oResult = new Result($iPlaceID);
                $iParentPlaceID = $aPlace['parent_place_id'];

                if ($bDoInterpolation && $iMaxRank >= 30) {
                    if ($aPlace['rank_address'] <=27) {
                        $iDistance = 0.001;
                    }
                    $aHouse = $this->lookupInterpolation($sPointSQL, $iDistance);

                    if ($aHouse) {
                        $oResult = new Result($aHouse['place_id'], Result::TABLE_OSMLINE);
                        $oResult->iHouseNumber = closestHouseNumber($aHouse);
                    }
                }

                // if street and maxrank > streetlevel
                if (($aPlace['rank_address'] <=27)&& $iMaxRank > 27) {
                    // find the closest object (up to a certain radius) of which the street is a parent of
                    $sSQL = ' select place_id,parent_place_id,rank_address,country_code,';
                    $sSQL .= ' ST_distance('.$sPointSQL.', geometry) as distance';
                    $sSQL .= ' FROM ';
                    $sSQL .= ' placex';
                    // radius ?
                    $sSQL .= ' WHERE ST_DWithin('.$sPointSQL.', geometry, 0.001)';
                    $sSQL .= ' AND parent_place_id = '.$iPlaceID;
                    $sSQL .= ' and rank_address != 28';
                    $sSQL .= ' and (name is not null or housenumber is not null)';
                    $sSQL .= ' and class not in (\'railway\',\'tunnel\',\'bridge\',\'man_made\')';
                    $sSQL .= ' and indexed_status = 0 and linked_place_id is null';
                    $sSQL .= ' ORDER BY distance ASC limit 1';
                    if (CONST_Debug) var_dump($sSQL);
                    $aStreet = chksql(
                        $this->oDB->getRow($sSQL),
                        'Could not determine closest place.'
                    );
                    if ($aStreet) {
                        $iDistance = $aStreet['distance'];
                        $iPlaceID = $aStreet['place_id'];
                        $oResult = new Result($iPlaceID);
                        $iParentPlaceID = $aStreet['parent_place_id'];

                        if ($bDoInterpolation && $iMaxRank >= 30) {
                            $aHouse = $this->lookupInterpolation($sPointSQL, $iDistance, $iParentPlaceID);

                            if ($aHouse) {
                                $oResult = new Result($aHouse['place_id'], Result::TABLE_OSMLINE);
                                $oResult->iHouseNumber = closestHouseNumber($aHouse);
                            }
                        }
                    }
                }

                  // In the US we can check TIGER data for nearest housenumber
                if (CONST_Use_US_Tiger_Data && $aPlace['country_code'] == 'us' && $this->iMaxRank >= 28) {
                    $fSearchDiam = $aPlace['rank_address'] > 28 ? $aPlace['distance'] : 0.001;
                    $sSQL = 'SELECT place_id,parent_place_id,30 as rank_search,';
                    $sSQL .= 'ST_LineLocatePoint(linegeo,'.$sPointSQL.') as fraction,';
                    $sSQL .= 'ST_distance('.$sPointSQL.', linegeo) as distance,';
                    $sSQL .= 'startnumber,endnumber,interpolationtype';
                    $sSQL .= ' FROM location_property_tiger WHERE parent_place_id = '.$oResult->iId;
                    $sSQL .= ' AND ST_DWithin('.$sPointSQL.', linegeo, '.$fSearchDiam.')';
                    $sSQL .= ' ORDER BY distance ASC limit 1';
                    if (CONST_Debug) var_dump($sSQL);
                    $aPlaceTiger = chksql(
                        $this->oDB->getRow($sSQL),
                        'Could not determine closest Tiger place.'
                    );
                    if ($aPlaceTiger) {
                        if (CONST_Debug) var_dump('found Tiger housenumber', $aPlaceTiger);
                        $aPlace = $aPlaceTiger;
                        $oResult = new Result($aPlace['place_id'], Result::TABLE_TIGER);
                        $oResult->iHouseNumber = closestHouseNumber($aPlaceTiger);
                    }
                }
            // if no POI or street is found ...
            } else {
                $oResult = $this->PolygonFunctions($sPointSQL, $iMaxRank);
            }
            // lower than street level ($iMaxRank < 26 )
        } else {
            $oResult = $this->PolygonFunctions($sPointSQL, $iMaxRank);
        }
        return $oResult;
    }
}
