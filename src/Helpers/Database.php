<?php
namespace KirchenImWeb\Helpers;

use PDO;
use PDOException;

/**
 * Class Database
 *
 * @package KirchenImWeb\Helpers
 */
class Database extends AbstractHelper {
    private $connection;

    protected function __construct() {
        $options  = [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_CLASS
        ];
        try {
            $this->connection = new PDO('mysql:host='.DATABASE_HOSTNAME.';dbname='.DATABASE_NAME.';charset=utf8', DATABASE_USERNAME, DATABASE_PASSWORD, $options);
        } catch (PDOException $e) {
            echo '<h1>Datenbank-Verbindung fehlgeschlagen</h1>';
        }
    }

    public function getEntries() {
        $websites = Configuration::getInstance()->websites;
        $query = 'SELECT id, lat, lon, name, street, postalCode, city, country, denomination, churches.type';
        foreach ($websites as $websiteId => $websiteName) {
            $query .= ', ' .$websiteId . '.url AS ' . $websiteId;
        }
        $query .= ' FROM churches ';
        foreach ($websites as $websiteId => $websiteName) {
            $query .= 'LEFT JOIN websites AS ' . $websiteId . ' ON ' . $websiteId . '.churchId = churches.id
                AND ' . $websiteId . '.type = "' . $websiteId . '" ';
        }
        $statement = $this->connection->query($query);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFilteredEntries($filters, $websites, $compare = false) {
        // Query churches
        $query = 'SELECT id, name, postalCode, city, country, denomination, churches.type';
        foreach ($websites as $websiteId => $websiteName) {
            $query .= ', ' .$websiteId . '.url AS ' . $websiteId . ', ' . $websiteId . '.followers AS ' . $websiteId . '_followers';
        }
        $query .= ' FROM churches ';

        // .... get the URLs to show.
        foreach ($websites as $websiteId => $websiteName) {
            $query .= 'LEFT JOIN websites AS ' . $websiteId . ' ON ' . $websiteId . '.churchId = churches.id
								AND ' . $websiteId . '.type = "' . $websiteId . '" ';
        }

        // ... and apply the filters
        $conditions = array();
        if ($filters['name'] != '') {
            array_push($conditions, 'name LIKE :name ');
        }
        if ($filters['postalCode'] != 0) {
            array_push($conditions, 'postalCode = :postalCode ');
        }
        if ($filters['city'] != '') {
            array_push($conditions, 'city LIKE :city ');
        }
        if ($filters['country'] != '') {
            array_push($conditions, 'country = :country ');
        }
        if ($filters['denomination'] != '') {
            array_push($conditions, 'denomination = :denomination ');
        }
        if ($filters['type'] != '') {
            array_push($conditions, 'churches.type = :ctype ');
        }
        if ($filters['hasWebsiteType'] != '') {
            array_push($conditions, 'EXISTS (SELECT * FROM websites WHERE id = churchId AND type = :wtype) ');
        }
        if ($compare) {
            // restrict to churches with at least one profile with followers set
            $compare_conditions = array();
            foreach (Configuration::getInstance()->networksToCompare as $websiteId => $websiteName) {
                array_push($compare_conditions, '(' . $websiteId . '.followers IS NOT NULL AND ' . $websiteId . '.followers > 0) ');
            }
            if (sizeof($compare_conditions) > 0) {
                $only_socialmedia_compare_condition = '';
                foreach ($compare_conditions as $condition) {
                    $only_socialmedia_compare_condition .= ' OR ' . $condition;
                }
                $only_socialmedia_compare_condition = preg_replace('/ OR/', '(', $only_socialmedia_compare_condition, 1) . ')';
                array_push($conditions, $only_socialmedia_compare_condition);
            }
        }

        if (sizeof($conditions) > 0) {
            $whereclause = '';
            foreach ($conditions as $condition) {
                $whereclause .= ' AND ' . $condition;
            }
            $whereclause = preg_replace('/ AND/', ' WHERE', $whereclause, 1);
            $query .= $whereclause;
            $query .= 'ORDER BY country, postalCode';
        } else {
            $query .= 'ORDER BY churches.timestamp DESC, id DESC LIMIT 25';
        }

        $statement = $this->connection->prepare($query);
        if ($filters['name'] != '') {
            $name = '%' . $filters['name'] . '%';
            $statement->bindParam(':name', $name);
        }
        if ($filters['postalCode'] != 0) {
            $statement->bindParam(':postalCode', $filters['postalCode']);
        }
        if ($filters['city'] != '') {
            $city = '%' . $filters['city'] . '%';
            $statement->bindParam(':city', $city);
        }
        if ($filters['country'] != '') {
            $statement->bindParam(':country', $filters['country']);
        }
        if ($filters['denomination'] != '') {
            $statement->bindParam(':denomination', $filters['denomination']);
        }
        if ($filters['type'] != '') {
            $statement->bindParam(':ctype', $filters['type']);
        }
        if ($filters['hasWebsiteType'] != '') {
            $statement->bindParam(':wtype', $filters['hasWebsiteType']);
        }

        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFaultyEntries() {
        $faultyEntries = [];

        $statement = $this->connection->query('SELECT * FROM `churches` WHERE street is NOT NULL AND (lat IS NULL or lon IS NULL)');
        $faultyEntries['geolocation_missing'] = $statement->fetchAll(PDO::FETCH_ASSOC);

        $networksToCompareAsStrings = array();
        foreach(Configuration::getInstance()->networksToCompare as $type => $typeName) {
            array_push($networksToCompareAsStrings, "'" . $type . "'");
        }
        $networksToCompareList = implode(', ', $networksToCompareAsStrings);
        $statement = $this->connection->prepare('SELECT churchId, url from websites 
			WHERE type IN (' . $networksToCompareList . ') AND (followers IS NULL AND timestamp < NOW())
			ORDER BY type, churchId');
        $statement->execute();
        $faultyEntries['followers_missing'] = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $faultyEntries;
    }

    public function getRecentlyAddedEntries() {
        $showWebsites = Configuration::getInstance()->preselectedWebsites;

        $query = 'SELECT id, name, postalCode, city, country, denomination, churches.type';
        foreach ($showWebsites as $websiteId => $websiteName) {
            $query .= ', ' .$websiteId . '.url AS ' . $websiteId;
        }
        $query .= ' FROM churches ';

        // .... get the URLs to show.
        foreach ($showWebsites as $websiteId => $websiteName) {
            $query .= 'LEFT JOIN websites AS ' . $websiteId . ' ON ' . $websiteId . '.churchId = churches.id 
								AND ' . $websiteId . '.type = "' . $websiteId . '" ';
        }

        $query .= ' ORDER BY churches.timestamp DESC, id DESC LIMIT 25';

        $statement = $this->connection->prepare($query);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getParentEntries() {
        $statement = $this->connection->query('SELECT id, name FROM churches
			WHERE hasChildren = 1
			ORDER BY name');
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getEntry($id, $childrenRecursive = false) {
        $id = intval($id);

        // Query for the entry itself
        $statement = $this->connection->prepare('SELECT churches.id, churches.name, churches.street, churches.postalCode, churches.city, churches.country, 
            churches.lat, churches.lon, churches.denomination, churches.type, churches.hasChildren,
            parent.id AS parentId, parent.name AS parentName FROM churches
            LEFT JOIN churches AS parent ON parent.id = churches.parentId
            WHERE churches.id = :id');
        $statement->bindParam(':id', $id);
        $statement->execute();

        $data = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            return false;
        }

        // Query for the children
        $data['children'] = $this->getChildrenOfEntry($id, $childrenRecursive);

        // Query for the websites
        $statement = $this->connection->prepare('SELECT url, type, followers FROM websites
            WHERE churchId = :id
            ORDER BY type ASC');
        $statement->bindParam(':id', $id);
        $statement->execute();
        $data['websites'] = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $data;
    }

    private function getChildrenOfEntry($parentId, $recursive) {
        $statement = $this->connection->prepare('SELECT id, name FROM churches
            WHERE parentId = :parentId
            ORDER BY name');
        $statement->bindParam(':parentId', $parentId);
        $statement->execute();
        $children = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($recursive) {
            foreach ($children as $key => $value) {
                $children[$key]['children'] = $this->getChildrenOfEntry($value{'id'}, true);
            }
        }
        return $children;
    }

    public function getIds() {
        $statement = $this->connection->query('SELECT id FROM churches');
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getURLsForUpdate($networksToCompareList, $limit) {
        $query = 'SELECT websiteId, churchId, url, type, followers, timestamp
            FROM websites
            WHERE type IN (' . $networksToCompareList . ')
            ORDER BY timestamp 
            LIMIT ' . $limit;
        $statement = $this->connection->query($query);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateFollowers($websiteId, $followers) {
        if ($followers === false) {
            $statement = $this->connection->prepare('UPDATE websites
                SET timestamp = NOW()
                WHERE websiteId = :websiteId');
        } else {
            $statement = $this->connection->prepare('UPDATE websites 
			    SET followers = :followers, timestamp = NOW()
			    WHERE websiteId = :websiteId');
            $statement->bindParam(':followers', $followers, PDO::PARAM_INT);
        }
        $statement->bindParam(':websiteId', $websiteId);
        return $statement->execute();
    }

    public function addFollowers($websiteId, $followers) {
	    $statement = $this->connection->prepare('INSERT INTO followers 
			    (websiteId, followers, date)
			    VALUES (:websiteId, :followers, NOW())');
	    $statement->bindParam(':websiteId', $websiteId);
	    $statement->bindParam(':followers', $followers, PDO::PARAM_INT);
	    return $statement->execute();
    }

    public function getTotalCount() {
        $queryTotal = 'SELECT count(*) AS count FROM churches';
        $statementTotal = $this->connection->query($queryTotal);
        return $statementTotal->fetch(PDO::FETCH_ASSOC);
    }

    public function getStatsByCountry() {
        $queryByCountry = 'SELECT count(*) AS count, country as countryCode FROM churches GROUP BY country ORDER BY count DESC';
        $statementByCountry = $this->connection->query($queryByCountry);
        return $statementByCountry->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStatsByDenomination() {
        $queryByDenomination = 'SELECT count(*) AS count, denomination FROM churches GROUP BY denomination';
        $statementByDenomination = $this->connection->query($queryByDenomination);
        return $statementByDenomination->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStatsByType() {
        $queryByType = 'SELECT count(*) AS count, type FROM churches GROUP BY type ORDER BY count DESC';
        $statementByType = $this->connection->query($queryByType);
        return $statementByType->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStatsByWebsite() {
        $queryByWebsite = 'SELECT count(*) AS count, type FROM websites GROUP BY type ORDER BY count DESC';
        $statementByWebsite = $this->connection->query($queryByWebsite);
        return $statementByWebsite->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStatsHTTPS() {
        $queryByWebsiteHTTPS = 'SELECT w1.type, IFNULL(count, 0) as count, IFNULL(countHTTPS, 0) as countHTTPS  
            FROM (
                SELECT count(*) AS count, type FROM websites
                WHERE type IN ("web", "rss", "blog") GROUP BY type
            ) as w1
            LEFT JOIN (
                SELECT count(*) AS countHTTPS, type FROM websites
                WHERE url LIKE "https://%" AND type IN ("web", "rss", "blog") GROUP BY type
            ) as w2 
            ON w1.type = w2.type
            ORDER BY count DESC';
        $statementByWebsiteHTTPS = $this->connection->query($queryByWebsiteHTTPS);
        return $statementByWebsiteHTTPS->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Adds a church.
     *
     * @param array $data the church data
     *
     * @return number the id of the created church.
     */
    function addChurch($data) {
        $urls = $data['urls'];

        // Add church to the database.
        $statement = $this->connection->prepare('
			INSERT INTO churches (name, street, postalCode, city, country, lat, lon, denomination, type, hasChildren, timestamp)
			VALUES (:name, :street, :postalCode, :city, :countryCode, :lat, :lon, :denomination, :type, :hasChildren, NOW())');

        $statement->bindParam(':name', $data['name']);
        $statement->bindParam(':street', $data['street']);
        $statement->bindParam(':postalCode', $data['postalCode']);
        $statement->bindParam(':city', $data['city']);
        $statement->bindParam(':countryCode', $data['countryCode']);
        $statement->bindParam(':lat', $data['lat']);
        $statement->bindParam(':lon', $data['lon']);
        $statement->bindParam(':denomination', $data['denomination']);
        $statement->bindParam(':type', $data['type']);
        $statement->bindParam(':hasChildren', $data['hasChildren'], PDO::PARAM_INT);
        $statement->execute();

        $churchId = $this->connection->lastInsertId();

        // Set parent id.
        if ($data['parentId'] != 0) {
            $statement = $this->connection->prepare('UPDATE churches SET parentId = :parentId
				WHERE id = :id');
            $statement->bindParam(':parentId', $data['parentId'], PDO::PARAM_INT);
            $statement->bindParam(':id', $churchId, PDO::PARAM_INT);
            $statement->execute();
        }

        // Add the URLs to the database.
        $statement = $this->connection->prepare('INSERT INTO websites (churchId, type, url) 
			VALUES (:churchId, :type, :url)');
        foreach($urls as $type => $url) {
            $statement->bindParam(':churchId', $churchId);
            $statement->bindParam(':type', $type);
            $statement->bindParam(':url', $url);
            $statement->execute();
        }

        return $this->getEntry(intval($churchId));
    }
}