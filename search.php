<?php

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

$escaped_search_str = $conn->real_escape_string($_GET['q']);

$search_keywords = preg_split("/[\s,\[\]\(\)+\\/-]+/", $escaped_search_str);

//print_r($search_keywords);

foreach ($search_keywords as $sk) {
    $sql = "SELECT keywords.keyword AS k, urls.url AS u, urls.reference_count as r
            FROM keywords
                LEFT JOIN keyword_url_relation
                    ON keyword_url_relation.keyword_id = keywords.keyword_id
                LEFT JOIN urls
                    ON urls.url_id = keyword_url_relation.url_id
            WHERE keywords.keyword = '$sk'
            ORDER BY r DESC";
    $res = $conn->query($sql);
    foreach ($res as $ir) {
        echo "<a href='" . $ir['u'] . "'>" . $ir['u'] . "<a/><br/>";
    }
}

?>