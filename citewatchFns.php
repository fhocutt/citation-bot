<?php
function citation_is_redirect($type, $id) {
  #TODO
  #$db = udbconnect("yarrow");
  #$sql = "SELECT $type, redirect FROM cite_$type WHERE $type='$id'";
  #$result = mysql_query($sql);
  #$results = mysql_fetch_array($result, MYSQL_ASSOC);
  #mysql_close();
  if ($result) {
    if ($results) {
      return $results["redirect"] ? 1 : 0;
    } else {
      // this page isn't in our mysql database
      $page_status = isRedirect("Template:Cite $type/$id");
      if ($page_status[0] == 1) {
        return 2; // Page exists; we need to check that the redirect has been created.
      } else {
        return $page_status[0]; // 0 or -1
      }
    }
  } else {
    // On error consult wikipedia API
    return (isRedirect("Template:Cite $type/$id"));
  }
}

function doi_citation_exists($doi) {
  $db = udbconnect("yarrow"); #TODO
  #$sql = "SELECT doi FROM cite_doi WHERE doi='" . addslashes($doi) . "'";
  #$result = mysql_query($sql);
  #$results = mysql_fetch_row($result);
  #mysql_close();
  if (FALSE && $result) {
    if ($results[0]) {
      return true;
    } else {
      $doi_page = "Template:Cite doi/" . anchorencode($doi);
      if (articleID($doi_page)) {
        log_citation("doi", $doi);
        return true;
      } else {
        return false;
      }
    }
  } else {
    // On error consult wikipedia API
    $doi_page = "Template:Cite doi/" . anchorencode($doi);
    if ($aid = articleID($doi_page)) {
      log_citation("doi", $doi);
      return $aid;
    } else {
      return false;
    }
  }
}

function log_citation($type, $source, $target = false) {
  $db = udbconnect("yarrow"); #TODO
  return NULL;

  $sql = "INSERT INTO cite_$type SET $type='" . addslashes($source) . "'"
  . ($type == "doi" ? "" : ", redirect='" . addslashes($target) . "'");

  $result = mysql_query($sql);
  return $result ? true : false;
}