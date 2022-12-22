<?php

set_time_limit(0);

function get_url_parts($url) {
    preg_match("/(https?:\/\/[www.]*)([A-Za-z0-9-_\.]*)([A-Za-z0-9-_\/\.:]*)([\\?]*)([^#]*)([#]*)([A-Za-z0-9-_\/\.]*)/", $url, $url_parts);
    return array_slice($url_parts, 1);
}

/*   SERVER SETUP   */

// server authentication info
$sname = "localhost";
$usr = "root";
$pw = "";

// database name
$dbname = "search";

// open the database connection
$conn = new mysqli($sname, $usr, $pw, $dbname);

// exit the program if connection failed
if ($conn->connect_error) {
    die("Failed to connect: ".$conn->connect_error);
}

/*   CURL HTTP REQUEST SETUP   */

// create curl object
$ch = curl_init();

// get the output as a string
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

// make curl follow redirects
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

//
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

//
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

//
curl_setopt($ch, CURLOPT_TIMEOUT, 3);

/*   MAIN LOOP SETUP   */

// create dom document
$doc = new DomDocument();

// list of all html tags to read keywords from
$all_tags_to_check_keywords = ['title', 'h1', 'h2', 'h3'];

// set array of unindexed urls to initial list from db
$unindexed_urls = $conn->query("SELECT url_id, url FROM urls WHERE parsed_date < now() - interval 3 year");
echo $unindexed_urls->fetch_row()[1];
//die();

// loop increment var
$i = 0;

// URL OVERRIDE
$override = false;
$override_url = '';
if ($override) {
    $parts = get_url_parts($override_url);
    $conn->query("INSERT INTO domains (domain) VALUES ('$parts[1]') ON DUPLICATE KEY UPDATE domain_id=domain_id");
    $sql = "INSERT INTO urls (domain_id, url)
            SELECT domain_id, '" . $conn->real_escape_string($parts[0] . $parts[1] . $parts[2]. $parts[3]) . "' 
            FROM domains WHERE domains.domain='" . $conn->real_escape_string($parts[1]) . "' 
            ON DUPLICATE KEY UPDATE url_id=url_id";
    $conn->query($sql);
    $res = $conn->query("SELECT url_id FROM urls WHERE url='" . $conn->real_escape_string($parts[0] . $parts[1] . $parts[2] . $parts[3]) . "'");
    $override_url_id = $res->fetch_row()[0];
}

/*   MAIN WEB SCRAPER LOOP   */

while (true) {

    // if overriden
    if ($override) {
        $url = $override_url;
        $url_id = $override_url_id;
    }

    // if there are no unindexed urls left
    else if ($unindexed_urls->num_rows == 0) {

        // try to get more from the db
        $unindexed_urls = $conn->query("SELECT url FROM urls WHERE parsed_date < now() - interval 3 year");

        // if there are none from db
        if ($unindexed_urls->num_rows == 0) {

            // just exit the program since there are no more urls
            break;
        }
    }

    if (!$override) {

        // set the current url and url id to a new unindexed url and corresponding url id
        [$url_id, $url] = $unindexed_urls->fetch_row();
    }

    ob_start();
    echo $url_id . "<br>";
    ob_flush();

    // parse url
    $url_parts = get_url_parts($url);

    echo $url . "<br>";

    // set url for the https request
    curl_setopt($ch, CURLOPT_URL, $url);

    // send request
    $res = curl_exec($ch);

    // skip url if request failed
    if (!$res) {
        if ($override) {
            echo "err<br>";
            break;
        }
        continue;
    }

    // load the result of the https request into the dom document
    $doc->loadHTML($res);

    /*   KEYWORD SCRAPING AND PARSING   */

    // empty the keywords string
    $all_keywords_str = "";

    // search every element with a tag from the list of keyword tags
    // and add the text to the keywords string
    foreach ($all_tags_to_check_keywords as $tag) {
        foreach ($doc->getElementsByTagName($tag) as $element) {
            $all_keywords_str .= " " . $element->textContent;
        }
    }

    // split the keywords by special characters and remove duplicates
    $all_keywords = array_unique(preg_split("/[^A-Za-z0-9_']*/", $all_keywords_str), SORT_STRING);
    echo $all_keywords_str;

    // Start a transaction so that all of the queries for this url are atomic
    $conn->query("START TRANSACTION");
    
    // ensure the domain is in the db
    $conn->query("INSERT INTO domains (domain) VALUES ('$url_parts[1]') ON DUPLICATE KEY UPDATE domain_id=domain_id");
    
    // iterate over every keyword
    foreach ($all_keywords as $keyword) {
    
        // ignore the keyword if it is empty or one letter
        if ($keyword == '' || strlen($keyword) == 1) {
            continue;
        }
    
        // add the keyword into the db if it has not yet been added
        $conn->query("INSERT INTO keywords (keyword) VALUES ('" . $conn->real_escape_string($keyword) . "') ON DUPLICATE KEY UPDATE keyword_id=keyword_id");

        // add an entry in the keyword-url relation table for this keyword-url combination
        $sql = "INSERT INTO keyword_url_relation (keyword_id, url_id) 
                SELECT keyword_id, '$url_id' FROM keywords WHERE keywords.keyword='" . $conn->real_escape_string($keyword) . "' 
                ON DUPLICATE KEY UPDATE keyword_url_relation.keyword_id=keyword_url_relation.keyword_id";
        $conn->query($sql);
    }
    
    /*   URL REFERENCES   */

    // create new array of checked urls with the current site url as the first element
    $arr_of_checked_urls[] = $url_parts[1] . $url_parts[2];
    
    // iterate over every link element in the DOM
    foreach ($doc->getElementsByTagName('a') as $element) {
    
        // get the link
        $href = $element->getAttribute('href');
    
        // parse the link
        $href_parts = get_url_parts($href);
    
        // link has no domain or same domain as the current site
        if (!isset($href_parts[1]) || $href_parts[1] == $url_parts[1]) {
    
            // empty link or same-page section link
            if (!isset($href_parts[2])) {
                // ignore
            }
    
            // same-domain link or relative link
            else {
    
                $calc_link = $href_parts[2]  . (isset($href_parts[3]) ? $href_parts[3] : "");

                // check if this url has already been added by this webpage
                if (!in_array($url_parts[1] . $calc_link, $arr_of_checked_urls)) {
    
                    // add to urls but leave reference counts at zero
                    $sql = "INSERT INTO urls (domain_id, url)
                            SELECT domain_id, '" . $url_parts[0] . $url_parts[1] . $conn->real_escape_string($calc_link) . "' FROM domains WHERE domains.domain='" . $url_parts[1] . "' 
                            ON DUPLICATE KEY UPDATE url_id=url_id";
                    $conn->query($sql);
    
                    // add this url to the list of checked urls
                    $arr_of_checked_urls[] = $url_parts[1] . $calc_link;
                }
            }
        }
    
        // external domain link
        else {

            $calc_link = $href_parts[1] . $href_parts[2]  . (isset($href_parts[3]) ? $href_parts[3] : "");
    
            // check if this url has already been added by this webpage
            if (!in_array($calc_link, $arr_of_checked_urls)) {
    
                // make sure the domain is in the db
                $conn->query("INSERT INTO domains (domain) VALUES ('" . $conn->real_escape_string($href_parts[1]) . "') ON DUPLICATE KEY UPDATE domain_id=domain_id");
    
                // add to urls and increment reference count if already present
                $sql = "INSERT INTO urls (domain_id, url)
                        SELECT domain_id, '" . $conn->real_escape_string($href_parts[0] . $calc_link) . "' 
                        FROM domains WHERE domains.domain='" . $conn->real_escape_string($href_parts[1]) . "' 
                        ON DUPLICATE KEY UPDATE reference_count=reference_count+1";
                $conn->query($sql);
    
                // add this url to the list of checked urls
                $arr_of_checked_urls[] = $calc_link;
            }
        }
    }
    
    // update url parse date
    $sql = "INSERT INTO urls (domain_id, url, parsed_date)
            SELECT domain_id, '" . $url_parts[0] . $url_parts[1] . $url_parts[2] . $url_parts[3] . "', now() FROM domains WHERE domains.domain='" . $url_parts[1] . "' 
            ON DUPLICATE KEY UPDATE parsed_date=now()";
    $conn->query($sql);
    
    // commit all changes to the database
    $conn->query("COMMIT");
    
    // change url
    $url = "";

    // increment loop counter
    $i++;

    if ($override) {
        break;
    }
}

curl_close($ch);

?>