<?php
require_once "objects.php";

// TEMPLATE //
class Template extends Item {
  const PLACEHOLDER_TEXT = '# # # Citation bot : template placeholder %s # # #';
  const REGEXP = '~\{\{(?:[^\{]|\{[^\{])+?\}\}~s';
  const TREAT_IDENTICAL_SEPARATELY = false;

  protected $name, $param, $initial_param, $initial_author_params, $citation_template, $mod_dashes;

  public function parseText($text) {
    $this->rawtext = $text;
    $pipe_pos = strpos($text, '|');
    if ($pipe_pos) {
      $this->name = substr($text, 2, $pipe_pos - 2); # Remove {{ and }}
      $this->splitParams(substr($text, $pipe_pos + 1, -2));
    } else {
      $this->name = substr($text, 2, -2);
      $this->param = null;
    }
    if ($this->param) {
      foreach ($this->param as $p) {
        $this->initial_param[$p->param] = $p->val;
      }
    }

  }

  protected function splitParams($text) {
    // | [pre] [param] [eq] [value] [post]
    $text = preg_replace('~(\[\[[^\[\]]+)\|([^\[\]]+\]\])~', "$1" . PIPE_PLACEHOLDER . "$2", $text);
    if ($this->wikiname() == 'cite doi') {
      $text = preg_replace('~d?o?i?\s*[:.,;>]*\s*(10\.\S+).*?(\s*)$~', "$1$2", $text);
    }

    $params = explode('|', $text);
    foreach ($params as $i => $text) {
      $this->param[$i] = new Parameter();
      $this->param[$i]->parseText($text);
    }
  }

  public function lowercaseParameters() {
    for ($i = 0; $i < count($this->param); $i++) {
      $this->param[$i]->param = strtolower($this->param[$i]->param);
    }

  }

  public function process() {
    var_dump($this->initial_param); //FIXME debug
    // FIXME: either up here or in the cases, should check for the presence of author parameters by seeing what overlap there is between $this->initial_param and $this->initial_author_params

    switch ($this->wikiname()) {
    case 'reflist':$this->page->has_reflist = true;
      break;
    case 'cite web':
      $this->useUnnamedParams();
      $this->getIdentifiersFromUrl();
      $this->tidy();
      if ($this->has('journal') || $this->has('bibcode') || $this->has('jstor') || $this->has('arxiv')) {
        if ($this->has('arxiv') && $this->blank('class')) {
          $this->rename('arxiv', 'eprint'); #TODO test arXiv handling
        }
        $this->name = 'Cite journal';
        $this->process();
      } elseif ($this->has('eprint')) {
        $this->name = 'Cite arxiv';
        $this->process();
      }
      $this->citation_template = true;
      break;
    case 'cite arxiv':
      $this->citation_template = true;
      $this->useUnnamedParams();
      $this->expandByArxiv();
      $this->expandByDoi();
      $this->tidy();
      if ($this->has('journal')) {
        $this->name = 'Cite journal';
        $this->rename('eprint', 'arxiv');
        $this->forget('class');
      }
      break;
    case 'cite book':
      $this->citation_template = true;
      $this->handleEtAl();
      $this->useUnnamedParams();
      $this->getIdentifiersFromUrl();
      $this->idToParam();
      echo "\n* " . $this->get('title');
      $this->correctParamSpelling();
      if ($this->expandByGoogleBooks()) {
        echo "\n * Expanded from Google Books API";
      }

      $this->tidy();
      if ($this->findIsbn()) {
        echo "\n * Found ISBN " . $this->get('isbn');
      }

      break;
    case 'cite journal':case 'cite document':case 'cite encyclopaedia':case 'cite encyclopedia':case 'citation':
      $this->citation_template = true;
      echo "\n\n* Expand citation: " . $this->get('title');
      $this->useUnnamedParams();
      $this->getIdentifiersFromUrl();
      if ($this->useSici()) {
        echo "\n * Found and used SICI";
      }

      $this->idToParam();
      $this->getDoiFromText();
      // TODO: Check for the doi-inline template in the title
      $this->handleEtAl();
      $this->correctParamSpelling();
      $this->expandByPubmed(); //partly to try to find DOI
      $journal_type = $this->has("periodical") ? "periodical" : "journal";
      if ($this->expandByGoogleBooks()) {
        echo "\n * Expanded from Google Books API";
      }

      $this->sanitizeDoi();
      if ($this->verifyDoi()) {
        $this->expandByDoi();
      }
      $this->tidy(); // Do now to maximize quality of metadata for DOI searches, etc
      $this->expandByAdsabs(); //Primarily to try to find DOI
      $this->getDoiFromCrossref();
      $this->findPmid();
      $this->tidy();
      break;
    case 'ref doi':case 'ref pmid':case 'ref jstor':case 'ref pmc':
      $this->add_ref_tags = true;
      echo "\n * Added ref tags to {{{$this->name}}}" . tag();
      $this->name = 'Cite ' . substr($this->wikiname(), 4);
    case 'cite doi':case 'cite pmid':case 'cite jstor':case 'cite pmc':
      $type = substr($this->wikiname(), 5);
      $id = trim_identifier($this->param[0]->val);
      $linked_page = "Template:Cite $type/" . wikititle_encode($id);
      if (!getArticleId($linked_page)) {
        expand_cite_page($linked_page);
      }
      //TODO: how's this handling separate cite template pages?
    }
    if ($this->citation_template) {
      $this->correctParamSpelling();
      $this->checkUrl();
    }
  }

/*
//TODO FIXME ETC?
public function authors_exist() {
if (param in any of $author_parameters) {
return true;
} else {
return false;
}
}
 */

  protected function incomplete() {
    if ($this->blank('pages', 'page') || (preg_match('~no.+no|n/a|in press|none~', $this->get('pages') . $this->get('page')))) {
      return true;
    }
    if ($this->displayAuthors() >= $this->numberOfAuthors()) {
      return true;
    }
    //FIXME; compatible with not modifying author-related?
    return (!(
      ($this->has('journal') || $this->has('periodical'))
      && $this->has("volume")
      && ($this->has("issue") || $this->has('number'))
      && $this->has("title")
      && ($this->has("date") || $this->has("year"))
      && ($this->has("author2") || $this->has("last2") || $this->has('surname2'))
    ));
  }

  public function blank($param) {
    if (!$param) {
      return;
    }

    if (empty($this->param)) {
      return true;
    }

    if (!is_array($param)) {
      $param = array($param);
    }

    foreach ($this->param as $p) {
      if (in_array($p->param, $param) && trim($p->val) != '') {
        return false;
      }

    }
    return true;
  }

  public function addIfNew($param, $value) {
    if ($corrected_spelling = $common_mistakes[$param]) {
      $param = $corrected_spelling;
    }

    if (trim($value) == "") {
      return false;
    }

    if (substr($param, -4) > 0 || substr($param, -3) > 0 || substr($param, -2) > 30) {
      // Stop at 30 authors - or page codes will become cluttered!
      if ($this->get('last29') || $this->get('author29') || $this->get('surname29')) {
        $this->addIfNew('display-authors', 29);
      }

      return false;
    }

    preg_match('~\d+$~', $param, $auNo);
    $auNo = $auNo[0];

    switch ($param) {
    case "editor":case "editor-last":case "editor-first":
      $value = str_replace(array(",;", " and;", " and ", " ;", "  ", "+", "*"), array(";", ";", " ", ";", " ", "", ""), $value);
      if ($this->blank('editor') && $this->blank("editor-last") && $this->blank("editor-first")) {
        return $this->add($param, $value);
      } else {
        return false;
      }
    case 'editor4':case 'editor4-last':case 'editor4-first':
      $this->addIfNew('displayeditors', 29);
      return $this->add($param, $value);
      break;
    case "author":case "author1":case "last1":case "last":case "authors":
      //TODO: Is it ok to make these mods? maybe.
      $value = str_replace(array(",;", " and;", " and ", " ;", "  ", "+", "*", "’"), array(";", ";", " ", ";", " ", "", "", "'"), $value);
      //TODO: needs a test case for the apostrophe business
      $value = straighten_quotes($value);

      //FIXME: some sort of expanding here...
      if ($this->blank("last1") && $this->blank("last") && $this->blank("author") && $this->blank("author1") && $this->blank("editor") && $this->blank("editor-last") && $this->blank("editor-first")) {
        if (strpos($value, ',')) {
          $au = explode(',', $value);
          $this->add($param, formatSurname($au[0]));
          return $this->add('first' . (substr($param, -1) == '1' ? '1' : ''), formatForename(trim($au[1])));
        } else {
          return $this->add($param, $value);
        }
      }
      return false; //FIXME: what does that do?
    case "first":case "first1":

      $value = straighten_quotes($value);
      if ($this->blank("first") && $this->blank("first1") && $this->blank("author") && $this->blank('author1')) {
        return $this->add($param, $value);
      }

      return false;
    case "coauthor": //FIXME: not sure if this works as desired; what if it's pulling a "coauthor" from some field on the internet?
      echo "\n The \"coauthor\" parameter is deprecated. Please replace manually.";
    case "coauthors": //FIXME: this should convert "coauthors" to "authors" maybe, if "authors" doesn't exist.
      $value = straighten_quotes($value);
      $value = str_replace(array(",;", " and;", " and ", " ;", "  ", "+", "*"), array(";", ";", " ", ";", " ", "", ""), $value);
      if ($this->blank("last2") && $this->blank("coauthor") && $this->blank("coauthors") && $this->blank("author")) {
        return $this->add($param, $value);
      }

      // if authors doesn't exist, coauthors -> authors
      // if
      // Note; we shouldn't be using this parameter ever....
      return false;
    case "last2":case "last3":case "last4":case "last5":case "last6":case "last7":case "last8":case "last9":
    case "last10":case "last20":case "last30":case "last40":case "last50":case "last60":case "last70":case "last80":case "last90":
    case "last11":case "last21":case "last31":case "last41":case "last51":case "last61":case "last71":case "last81":case "last91":
    case "last12":case "last22":case "last32":case "last42":case "last52":case "last62":case "last72":case "last82":case "last92":
    case "last13":case "last23":case "last33":case "last43":case "last53":case "last63":case "last73":case "last83":case "last93":
    case "last14":case "last24":case "last34":case "last44":case "last54":case "last64":case "last74":case "last84":case "last94":
    case "last15":case "last25":case "last35":case "last45":case "last55":case "last65":case "last75":case "last85":case "last95":
    case "last16":case "last26":case "last36":case "last46":case "last56":case "last66":case "last76":case "last86":case "last96":
    case "last17":case "last27":case "last37":case "last47":case "last57":case "last67":case "last77":case "last87":case "last97":
    case "last18":case "last28":case "last38":case "last48":case "last58":case "last68":case "last78":case "last88":case "last98":
    case "last19":case "last29":case "last39":case "last49":case "last59":case "last69":case "last79":case "last89":case "last99":
    case "author2":case "author3":case "author4":case "author5":case "author6":case "author7":case "author8":case "author9":
    case "author10":case "author20":case "author30":case "author40":case "author50":case "author60":case "author70":case "author80":case "author90":
    case "author11":case "author21":case "author31":case "author41":case "author51":case "author61":case "author71":case "author81":case "author91":
    case "author12":case "author22":case "author32":case "author42":case "author52":case "author62":case "author72":case "author82":case "author92":
    case "author13":case "author23":case "author33":case "author43":case "author53":case "author63":case "author73":case "author83":case "author93":
    case "author14":case "author24":case "author34":case "author44":case "author54":case "author64":case "author74":case "author84":case "author94":
    case "author15":case "author25":case "author35":case "author45":case "author55":case "author65":case "author75":case "author85":case "author95":
    case "author16":case "author26":case "author36":case "author46":case "author56":case "author66":case "author76":case "author86":case "author96":
    case "author17":case "author27":case "author37":case "author47":case "author57":case "author67":case "author77":case "author87":case "author97":
    case "author18":case "author28":case "author38":case "author48":case "author58":case "author68":case "author78":case "author88":case "author98":
    case "author19":case "author29":case "author39":case "author49":case "author59":case "author69":case "author79":case "author89":case "author99":
      $value = str_replace(array(",;", " and;", " and ", " ;", "  ", "+", "*"), array(";", ";", " ", ";", " ", "", ""), $value);
      $value = straighten_quotes($value);
      if ($this->blank("last$auNo") && $this->blank("author$auNo")
        && $this->blank("coauthor") && $this->blank("coauthors")
        && strpos($this->get('author') . $this->get('authors'), ' and ') === false
        && strpos($this->get('author') . $this->get('authors'), '; ') === false
        && strpos($this->get('author') . $this->get('authors'), ' et al') === false
      ) {
        if (strpos($value, ',') && substr($param, 0, 3) == 'aut') {
          $au = explode(',', $value);
          $this->add('last' . $auNo, formatSurname($au[0]));
          return $this->addIfNew('first' . $auNo, formatForename(trim($au[1])));
        } else {
          return $this->add($param, $value);
        }
      }
      return false;
    case "first2":case "first3":case "first4":case "first5":case "first6":case "first7":case "first8":case "first9":
    case "first10":case "first11":case "first12":case "first13":case "first14":case "first15":case "first16":case "first17":case "first18":case "first19":
    case "first20":case "first21":case "first22":case "first23":case "first24":case "first25":case "first26":case "first27":case "first28":case "first29":
    case "first30":case "first31":case "first32":case "first33":case "first34":case "first35":case "first36":case "first37":case "first38":case "first39":
    case "first40":case "first41":case "first42":case "first43":case "first44":case "first45":case "first46":case "first47":case "first48":case "first49":
    case "first50":case "first51":case "first52":case "first53":case "first54":case "first55":case "first56":case "first57":case "first58":case "first59":
    case "first60":case "first61":case "first62":case "first63":case "first64":case "first65":case "first66":case "first67":case "first68":case "first69":
    case "first70":case "first71":case "first72":case "first73":case "first74":case "first75":case "first76":case "first77":case "first78":case "first79":
    case "first80":case "first81":case "first82":case "first83":case "first84":case "first85":case "first86":case "first87":case "first88":case "first89":
    case "first90":case "first91":case "first92":case "first93":case "first94":case "first95":case "first96":case "first97":case "first98":case "first99":
      $value = straighten_quotes($value);
      if ($this->blank($param)
        && under_two_authors($this->get('author')) && $this->blank("author" . $auNo)
        && $this->blank("coauthor") && $this->blank("coauthors")) {
        return $this->add($param, $value);
      }
      return false;
    case "date":
      if (preg_match("~^\d{4}$~", sanitize_string($value))) {
        // Not adding any date data beyond the year, so 'year' parameter is more suitable
        $param = "year";
      }
    // Don't break here; we want to go straight in to year;
    case "year":
      if (($this->blank("date") || trim(strtolower($this->get('date'))) == "in press")
        && ($this->blank("year") || trim(strtolower($this->get('year'))) == "in press")
      ) {
        return $this->add($param, $value);
      }
      return false;
    case "periodical":case "journal":
      if ($this->blank("journal") && $this->blank("periodical") && $this->blank("work")) {
        return $this->add($param, sanitize_string($value));
      }
      return false;
    case 'chapter':case 'contribution':
      if ($this->blank("chapter") && $this->blank("contribution")) {
        return $this->add($param, format_title_text($value));
      }
      return false;
    case "page":case "pages":
      if (($this->blank("pages") && $this->blank("page"))
        || strpos(strtolower($this->get('pages') . $this->get('page')), 'no') !== false
        || (strpos($value, chr(2013)) || (strpos($value, '-'))
          && !strpos($this->get('pages'), chr(2013))
          && !strpos($this->get('pages'), chr(150)) // Also en-dash
           && !strpos($this->get('pages'), chr(226)) // Also en-dash
           && !strpos($this->get('pages'), '-')
          && !strpos($this->get('pages'), '&ndash;'))
      ) {
        return $this->add($param, sanitize_string($value));
      }

      return false;
    case 'title':
      if ($this->blank($param)) {
        return $this->formatTitle(sanitize_string($value));
      }
      return false;
    case 'class':
      if ($this->blank($param) && strpos($this->get('eprint'), '/') === false) {
        return $this->add($param, sanitize_string($value));
      }
      return false;
    case 'doi':
      if ($this->blank($param) && preg_match('~(10\..+)$~', $value, $match)) {
        $this->add('doi', $match[0]);
        $this->verifyDoi();
        $this->expandByDoi();
        return true;
      }
      return false;
    case 'display-authors':case 'displayauthors':
      if ($this->blank('display-authors') && $this->blank('displayauthors')) {
        return $this->add($param, $value);
      }
      return false;
    case 'display-editors':case 'displayeditors':
      if ($this->blank('display-editors') && $this->blank('displayeditors')) {
        return $this->add($param, $value);
      }
      return false;
    case 'doi_brokendate':case 'doi_inactivedate':
      if ($this->blank('doi_brokendate') && $this->blank('doi_inactivedate')) {
        return $this->add($param, $value);
      }
      return false;
    case 'pmid':
      if ($this->blank($param)) {
        $this->add($param, sanitize_string($value));
        $this->expandByPubmed();
        if ($this->blank('doi')) {
          $this->getDoiFromCrossref();
        }

        return true;
      }
      return false;
    case 'author_separator':case 'author-separator':
      if ($this->blank($param)) {
        return $this->add($param, $value);
      }
      return false;
    case 'postscript':
      if ($this->blank($param)) {
        return $this->add($param, $value);
      }
      return false;
    default:
      if ($this->blank($param)) {
        return $this->add($param, sanitize_string($value));
      }
    }
  }

  protected function getIdentifiersFromUrl() {
    $url = $this->get('url');
    // JSTOR
    if (strpos($url, "jstor.org") !== false) {
      if (strpos($url, "sici")) {
        #Skip.  We can't do anything more with the SICI, unfortunately.
      } elseif (preg_match("~(?|(\d{6,})$|(\d{6,})[^\d%\-])~", $url, $match)) {
        if ($this->get('jstor')) {
          $this->forget('url');
        } else {
          $this->rename("url", "jstor", $match[1]);
        }
        if (strpos($this->name, 'web')) {
          $this->name = 'Cite journal';
        }

      }
    } else {
      if (preg_match(bibcode_regexp, urldecode($url), $bibcode)) {
        if (!$this->get('bibcode')) {
          $this->rename("url", "bibcode", urldecode($bibcode[1]));
        }
      } elseif (preg_match("~^https?://www\.pubmedcentral\.nih\.gov/articlerender.fcgi\?.*\bartid=(\d+)"
        . "|^http://www\.ncbi\.nlm\.nih\.gov/pmc/articles/PMC(\d+)~", $url, $match)) {
        if (!$this->get('pmc')) {
          $this->rename("url", "pmc", $match[1] . $match[2]);
        }
        if (strpos($this->name, 'web')) {
          $this->name = 'Cite journal';
        }

      } else if (preg_match("~^https?://d?x?\.?doi\.org/([^\?]*)~", $url, $match)) {
        quiet_echo("\n   ~ URL is hard-coded DOI; converting to use DOI parameter.");
        if (!$this->get('doi')) {
          $this->set("doi", urldecode($match[1]));
          $this->expandByDoi(1);
        }
        if (strpos($this->name, 'web')) {
          $this->name = 'Cite journal';
        }

      } elseif (preg_match("~10\.\d{4}/[^&\s\|\?]*~", $url, $match)) {
        quiet_echo("\n   ~ Recognized DOI in URL; dropping URL");
        if (!$this->get('doi')) {

          $this->set('doi', preg_replace("~(\.x)/(?:\w+)~", "$1", $match[0]));
          $this->expandByDoi(1);
        }
      } elseif (preg_match("~\barxiv.org/(?:pdf|abs)/(.+)$~", $url, $match)) {
        //ARXIV
        $this->addIfNew("arxiv", $match[1]);
        if (strpos($this->name, 'web')) {
          $this->name = 'Cite arxiv';
        }

      } else if (preg_match("~https?://www.ncbi.nlm.nih.gov/pubmed/.*?=?(\d{6,})~", $url, $match)) {
        if (!$this->get('pmid')) {
          $this->set('pmid', $match[1]);
        }
        if (strpos($this->name, 'web')) {
          $this->name = 'Cite journal';
        }

      } else if (preg_match("~^https?://www\.amazon(?P<domain>\.[\w\.]{1,7})/.*dp/(?P<id>\d+X?)~", $url, $match)) {
        if ($match['domain'] == ".com") {
          if ($this->get('asin')) {
            $this->forget('url');
          } else {
            $this->rename('url', 'asin', $match['id']);
          }
        } else {
          $this->set('id', $this->get('id') . " {{ASIN|{$match['id']}|country=" . str_replace(array(".co.", ".com.", "."), "", $match['domain']) . "}}");
          $this->forget('url');
          $this->forget('accessdate');
        }
        if (strpos($this->name, 'web')) {
          $this->name = 'Cite book';
        }

      }
    }
  }

  protected function getDoiFromText() {
    if ($this->blank('doi') && preg_match('~10\.\d{4}/[^&\s\|\}\{]*~', urldecode($this->parsedText()), $match))
    // Search the entire citation text for anything in a DOI format.
    // This is quite a broad match, so we need to ensure that no baggage has been tagged on to the end of the URL.
    {
      $this->addIfNew('doi', preg_replace("~(\.x)/(?:\w+)~", "$1", $match[0]));
    }

  }

  protected function getDoiFromCrossref() { #TODO test
  if ($doi = $this->get('doi')) {
    return $doi;
  }

    echo "\n - Checking CrossRef database for doi. " . tag();
    $title = $this->get('title');
    $journal = $this->get('journal');
    $author = $this->firstAuthor();
    $year = $this->get('year');
    $volume = $this->get('volume');
    $page_range = $this->pageRange();
    $start_page = $page_range[1];
    $end_page = $page_range[2];
    $issn = $this->get('issn');
    $url1 = trim($this->get('url'));
    $input = array($title, $journal, $author, $year, $volume, $start_page, $end_page, $issn, $url1);
    global $priorP;
    if ($input == $priorP['crossref']) {
      echo "\n   * Data not changed since last CrossRef search." . tag();
      return false;
    } else {
      $priorP['crossref'] = $input;
      global $crossRefId;
      if ($journal || $issn) {
        $url = "http://www.crossref.org/openurl/?noredirect=true&pid=$crossRefId"
        . ($title ? "&atitle=" . urlencode(deWikify($title)) : "")
        . ($author ? "&aulast=" . urlencode($author) : '')
        . ($start_page ? "&spage=" . urlencode($start_page) : '')
        . ($end_page > $start_page ? "&epage=" . urlencode($end_page) : '')
        . ($year ? "&date=" . urlencode(preg_replace("~([12]\d{3}).*~", "$1", $year)) : '')
        . ($volume ? "&volume=" . urlencode($volume) : '')
        . ($issn ? "&issn=$issn" : ($journal ? "&title=" . urlencode(deWikify($journal)) : ''));
        if (!($result = @simplexml_load_file($url)->query_result->body->query)) {
          echo "\n   * Error loading simpleXML file from CrossRef.";
        } else if ($result['status'] == 'malformed') {
          echo "\n   * Cannot search CrossRef: " . $result->msg;
        } else if ($result["status"] == "resolved") {
          return $result;
        }
      }
      global $fastMode;
      if ($fastMode || !$author || !($journal || $issn) || !$start_page) {
        return;
      }

      // If fail, try again with fewer constraints...
      echo "\n   x Full search failed. Dropping author & end_page... ";
      $url = "http://www.crossref.org/openurl/?noredirect=true&pid=$crossRefId";
      if ($title) {
        $url .= "&atitle=" . urlencode(deWikify($title));
      }

      if ($issn) {
        $url .= "&issn=$issn";
      } elseif ($journal) {
        $url .= "&title=" . urlencode(deWikify($journal));
      }

      if ($year) {
        $url .= "&date=" . urlencode($year);
      }

      if ($volume) {
        $url .= "&volume=" . urlencode($volume);
      }

      if ($start_page) {
        $url .= "&spage=" . urlencode($start_page);
      }

      if (!($result = @simplexml_load_file($url)->query_result->body->query)) {
        echo "\n   * Error loading simpleXML file from CrossRef." . tag();
      } else if ($result['status'] == 'malformed') {
        echo "\n   * Cannot search CrossRef: " . $result->msg;
      } else if ($result["status"] == "resolved") {
        echo " Successful!";
        return $result;
      }
    }
  }

  protected function getDoiFromWebpage() { #TODO test
  if ($doi = $this->has('doi')) {
    return $doi;
  }

    if ($url = trim($this->get('url')) && (strpos($url, "http://") !== false || strpos($url, "https://") !== false)) {
      $url = explode(" ", trim($url));
      $url = $url[0];
      $url = preg_replace("~\.full(\.pdf)?$~", ".abstract", $url);
      $url = preg_replace("~<!--.*-->~", '', $url);
      if (substr($url, -4) == ".pdf") {
        global $html_output;
        echo $html_output
        ? ("\n - Avoiding <a href=\"$url\">PDF URL</a>. <br>")
        : "\n - Avoiding PDF URL $url";
      } else {
        // Try using URL parameter
        global $urlsTried, $slow_mode;
        echo $html_output
        ? ("\n - Trying <a href=\"$url\">URL</a>. <br>")
        : "\n - Trying URL $url";
        // Metas might be hidden if we don't have access the the page, so try the abstract:

        if (@in_array($url, $urlsTried)) {
          echo "URL has been scraped already - and scrapped.<br>";
          return null;
        }
        //Check that it's not in the URL to start with
        if (preg_match("|/(10\.\d{4}/[^?]*)|i", urldecode($url), $doi)) {
          echo "Found DOI in URL." . tag();
          return $this->set('doi', $doi[1]);
        }

        //Try meta tags first.
        $meta = @get_meta_tags($url);
        if ($meta) {
          $this->addIfNew("pmid", $meta["citation_pmid"]);
          foreach ($meta as $oTag) {
            if (preg_match("~^\s*10\.\d{4}/\S*\s*~", $oTag)) {
              echo "Found DOI in meta tags" . tag();
              return $this->set('doi', $oTag);
            }
          }

        }
        if (!$slow_mode) {
          echo "\n -- Aborted: not running in 'slow_mode'!";
        } else if ($size[1] > 0 && $size[1] < 100000) { // TODO. The bot seems to keep crashing here; let's evaluate whether it's worth doing.  For now, restrict to slow mode.
          echo "\n -- Querying URL with reported file size of ", $size[1], "b...", $htmlOutput ? "<br>" : "\n";
          //Initiate cURL resource
          $ch = curl_init();

          curlSetup($ch, $url);
          $source = curl_exec($ch);
          if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 404) {
            echo " -- 404 returned from URL.", $htmlOutput ? "<br>" : "\n";
            // Try anyway.  There may still be metas.
          } else if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 501) {
            echo " -- 501 returned from URL.", $htmlOutput ? "<br>" : "\n";
            return false;
          }
          curl_close($ch);
          if (strlen($source) < 100000) {
            $doi = getDoiFromText($source, true);
            if (!$doi) {
              checkTextForMetas($source);
            }
          } else {
            echo "\n -- File size was too large. Abandoned.";
          }
        } else {
          echo $htmlOutput
          ? ("\n\n ** ERROR: PDF may have been too large to open.  File size: " . $size[1] . "b<br>")
          : "\n -- PDF too large ({$size[1]}b)";
        }
        if ($doi) {
          if (!preg_match("/>\d\.\d\.\w\w;\d/", $doi)) { //If the DOI contains a tag but doesn't conform to the usual syntax with square brackes, it's probably picked up an HTML entity.
            echo " -- DOI may have picked up some tags. ";
            $content = strip_tags(str_replace("<", " <", $source)); // if doi is superceded by a <tag>, any ensuing text would run into it when we removed tags unless we added a space before it!
            preg_match("~" . doiRegexp . "~Ui", $content, $dois); // What comes after doi, then any nonword, but before whitespace
            if ($dois[1]) {$doi = trim($dois[1]);
              echo " Removing them.<br>";} else {
              echo "More probably, the DOI was itself in a tag. CHECK it's right!<br>";
              //If we can't find it when tags have been removed, it might be in a <a> tag, for example.  Use it "neat"...
            }
          }
          $urlsTried[] = $url;
          $this->set('doi', urldecode($doi));
        } else {
          $urlsTried[] = $url;
          return false;
        }
        if ($doi) {
          echo " found doi $doi";
          $this->set('doi', $doi);
        } else {
          $urlsTried[] = $url; //Log barren urls so we don't search them again.
          echo " no doi found.";
        }
      }
    } else {
      echo "No valid URL specified.  ";
    }
  }

  protected function findPmid() {
    echo "\n - Searching PubMed... " . tag();
    $results = ($this->queryPubmed());
    if ($results[1] == 1) {
      $this->addIfNew('pmid', $results[0]);
    } else {
      echo " nothing found.";
      if (mb_strtolower(substr($citation[$cit_i + 2], 0, 8)) == "citation" && $this->blank('journal')) {
        // Check for ISBN, but only if it's a citation.  We should not risk a false positive by searching for an ISBN for a journal article!
        echo "\n - Checking for ISBN";
        if ($this->blank('isbn') && $title = $this->get("title")) {
          $this->set("isbn", findISBN($title, $this->firstAuthor()));
        } else {
          echo "\n  Already has an ISBN. ";
        }

      }
    }
  }

  protected function queryPubmed() {
/*
 *
 * Performs a search based on article data, using the DOI preferentially, and failing that, the rest of the article details.
 * Returns an array:
 *   [0] => PMID of first matching result
 *   [1] => total number of results
 *
 */
    if ($doi = $this->get('doi')) {
      $results = $this->doPumbedQuery(array("doi"), true);
      if ($results[1] == 1) {
        return $results;
      }

    }
    // If we've got this far, the DOI was unproductive or there was no DOI.

    if ($this->has("journal") && $this->has("volume") && $this->has("pages")) {
      $results = $this->doPumbedQuery(array("journal", "volume", "issue", "pages"));
      if ($results[1] == 1) {
        return $results;
      }

    }
    if ($this->has("title") && ($this->has("author") || $this->has("author") || $this->has("author1") || $this->has("author1"))) {
      $results = $this->doPumbedQuery(array("title", "author", "author", "author1", "author1"));
      if ($results[1] == 1) {
        return $results;
      }

      if ($results[1] > 1) {
        $results = $this->doPumbedQuery(array("title", "author", "author", "author1", "author1", "year", "date"));
        if ($results[1] == 1) {
          return $results;
        }

        if ($results[1] > 1) {
          $results = $this->doPumbedQuery(array("title", "author", "author", "author1", "author1", "year", "date", "volume", "issue"));
          if ($results[1] == 1) {
            return $results;
          }

        }
      }
    }
  }

  protected function doPumbedQuery($terms, $check_for_errors = false) {
    /* do_query
     *
     * Searches pubmed based on terms provided in an array.
     * Provide an array of wikipedia parameters which exist in $p, and this function will construct a Pubmed seach query and
     * return the results as array (first result, # of results)
     * If $check_for_errors is true, it will return 'fasle' on errors returned by pubmed
     */
    foreach ($terms as $term) {
      $key_index = array(
        'doi' => 'AID',
        'author1' => 'Author',
        'author' => 'Author',
        'issue' => 'Issue',
        'journal' => 'Journal',
        'pages' => 'Pagination',
        'page' => 'Pagination',
        'date' => 'Publication Date',
        'year' => 'Publication Date',
        'title' => 'Title',
        'pmid' => 'PMID',
        'volume' => 'Volume',
        ##Text Words [TW] , Title/Abstract [TIAB]
        ## Formatting: YYY/MM/DD Publication Date [DP]
      );
      $key = $key_index[mb_strtolower($term)];
      if ($key && $term && $val = $this->get($term)) {
        $query .= " AND (" . str_replace("%E2%80%93", "-", urlencode($val)) . "[$key])";
      }
    }
    $query = substr($query, 5);
    $url = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&tool=DOIbot&email=martins+pubmed@gmail.com&term=$query";
    $xml = simplexml_load_file($url);
    if ($check_for_errors && $xml->ErrorList) {
      echo $xml->ErrorList->PhraseNotFound
      ? " no results."
      : "\n - Errors detected in PMID search (" . print_r($xml->ErrorList, 1) . "); abandoned.";
      return array(null, 0);
    }

    return $xml ? array((string) $xml->IdList->Id[0], (string) $xml->Count) : array(null, 0); // first results; number of results
  }

  ### Obtain data from external database
  public function expandByArxiv() {
    if ($this->wikiname() == 'cite arxiv') {
      $arxiv_param = 'eprint';
      $this->rename('arxiv', 'eprint');
    } else {
      $arxiv_param = 'arxiv';
      $this->rename('eprint', 'arxiv');
    }
    $class = $this->get('class');
    $eprint = str_ireplace("arXiv:", "", $this->get('eprint') . $this->get('arxiv'));
    if ($class && substr($eprint, 0, strlen($class) + 1) == $class . '/') {
      $eprint = substr($eprint, strlen($class) + 1);
    }

    $this->set($arxiv_param, $eprint);

    if ($eprint) {
      echo "\n * Getting data from arXiv " . $eprint;
      $xml = simplexml_load_string(
        preg_replace("~(</?)(\w+):([^>]*>)~", "$1$2$3", file_get_contents("http://export.arxiv.org/api/query?start=0&max_results=1&id_list=$eprint"))
      );
    }
    if ($xml) {
      foreach ($xml->entry->author as $auth) {
        $i++;
        $name = $auth->name;
        if (preg_match("~(.+\.)(.+?)$~", $name, $names) || preg_match('~^\s*(\S+) (\S+)\s*$~', $name, $names)) {
          $this->addIfNew("last$i", $names[2]);
          $this->addIfNew("first$i", $names[1]);
        } else {
          $this->addIfNew("author$i", $name);
        }
      }
      $this->addIfNew("title", (string) $xml->entry->title);
      $this->addIfNew("class", (string) $xml->entry->category["term"]);
      $this->addIfNew("author", substr($authors, 2));
      $this->addIfNew("year", substr($xml->entry->published, 0, 4));
      $this->addIfNew("doi", (string) $xml->entry->arxivdoi);

      if ($xml->entry->arxivjournal_ref) {
        $journal_data = (string) $xml->entry->arxivjournal_ref;
        if (preg_match("~,(\(?([12]\d{3})\)?).*?$~u", $journal_data, $match)) {
          $journal_data = str_replace($match[1], "", $journal_data);
          $this->addIfNew("year", $match[1]);
        }
        if (preg_match("~\w?\d+-\w?\d+~", $journal_data, $match)) {
          $journal_data = str_replace($match[0], "", $journal_data);
          $this->addIfNew("pages", str_replace("--", en_dash, $match[0]));
        }
        if (preg_match("~(\d+)(?:\D+(\d+))?~", $journal_data, $match)) {
          $this->addIfNew("volume", $match[1]);
          $this->addIfNew("issue", $match[2]);
          $journal_data = preg_replace("~[\s:,;]*$~", "",
            str_replace(array($match[1], $match[2]), "", $journal_data));
        }
        $this->addIfNew("journal", $journal_data);
      } else {
        $this->addIfNew("year", date("Y", strtotime((string) $xml->entry->published)));
      }
      return true;
    }
    return false;
  }

  public function expandByAdsabs() {
    global $slow_mode;
    if ($slow_mode || $this->has('bibcode')) {
      echo "\n - Checking AdsAbs database";
      $url_root = "http://adsabs.harvard.edu/cgi-bin/abs_connect?data_type=XML&";
      if ($bibcode = $this->get("bibcode")) {
        $xml = simplexml_load_file($url_root . "bibcode=" . urlencode($bibcode));
      } elseif ($doi = $this->get('doi')) {
        $xml = simplexml_load_file($url_root . "doi=" . urlencode($doi));
      } elseif ($title = $this->get("title")) {
        $xml = simplexml_load_file($url_root . "title=" . urlencode('"' . $title . '"'));
        $inTitle = str_replace(array(" ", "\n", "\r"), "", (mb_strtolower($xml->record->title)));
        $dbTitle = str_replace(array(" ", "\n", "\r"), "", (mb_strtolower($title)));
        if (
          (strlen($inTitle) > 254 || strlen(dbTitle) > 254)
          ? strlen($inTitle) != strlen($dbTitle) || similar_text($inTitle, $dbTitle) / strlen($inTitle) < 0.98
          : levenshtein($inTitle, $dbTitle) > 3
        ) {
          echo "\n   Similar title not found in database";
          return false;
        }
      }
      if ($xml["retrieved"] != 1 && $journal = $this->get('journal')) {
        // try partial search using bibcode components:
        $xml = simplexml_load_file($url_root
          . "year=" . $this->get('year')
          . "&volume=" . $this->get('volume')
          . "&page=" . ($pages = $this->get('pages') ? $pages : $this->get('page'))
        );
        $journal_string = explode(",", (string) $xml->record->journal);
        $journal_fuzzyer = "~\bof\b|\bthe\b|\ba\beedings\b|\W~";
        if (strpos(mb_strtolower(preg_replace($journal_fuzzyer, "", $journal)),
          mb_strtolower(preg_replace($journal_fuzzyer, "", $journal_string[0]))) === false) {
          echo "\n   Match for pagination but database journal \"{$journal_string[0]}\" didn't match \"journal = $journal\"." . tag();
          return false;
        }
      }
      if ($xml["retrieved"] == 1) {
        echo tag();
        $this->addIfNew("bibcode", (string) $xml->record->bibcode);
        $this->addIfNew("title", (string) $xml->record->title);
        foreach ($xml->record->author as $author) {
          $this->addIfNew("author" . ++$i, $author);
        }
        $journal_string = explode(",", (string) $xml->record->journal);
        $journal_start = mb_strtolower($journal_string[0]);
        $this->addIfNew("volume", (string) $xml->record->volume);
        $this->addIfNew("issue", (string) $xml->record->issue);
        $this->addIfNew("year", preg_replace("~\D~", "", (string) $xml->record->pubdate));
        $this->addIfNew("pages", (string) $xml->record->page);
        if (preg_match("~\bthesis\b~ui", $journal_start)) {} elseif (substr($journal_start, 0, 6) == "eprint") {
          if (substr($journal_start, 7, 6) == "arxiv:") {
            if ($this->addIfNew("arxiv", substr($journal_start, 13))) {
              $this->expandByArxiv();
            }

          } else {
            $this->appendto('id', ' ' . substr($journal_start, 13));
          }
        } else {
          $this->addIfNew('journal', $journal_string[0]);
        }
        if ($this->addIfNew('doi', (string) $xml->record->DOI)) {
          $this->expandByDoi();
        }
        return true;
      } else {
        echo ": no record retrieved." . tag();
        return false;
      }
    } else {
      echo "\n - Skipping AdsAbs database: not in slow mode" . tag();
      return false;
    }
  }

  public function expandByDoi($force = false) {
    global $editing_cite_doi_template;
    $doi = $this->getWithoutComments('doi');
    if ($doi && ($force || $this->incomplete())) {
      if (preg_match('~^10\.2307/(\d+)$~', $doi)) {
        $this->addIfNew('jstor', substr($doi, 8));
      }

      $crossRef = $this->queryCrossref($doi);
      if ($crossRef) {
        echo "\n - Expanding from crossRef record" . tag();

        if ($crossRef->volume_title && $this->blank('journal')) {
          $this->addIfNew('chapter', $crossRef->article_title);
          if (strtolower($this->get('title')) == strtolower($crossRef->article_title)) {
            $this->forget('title');
          }
          $this->addIfNew('title', $crossRef->volume_title);
        } else {
          $this->addIfNew('title', $crossRef->article_title);
        }
        $this->addIfNew('series', $crossRef->series_title);
        $this->addIfNew("year", $crossRef->year);
        if ($this->blank(array('editor', 'editor1', 'editor-last', 'editor1-last')) && $crossRef->contributors->contributor) {
          foreach ($crossRef->contributors->contributor as $author) {
            if ($author["contributor_role"] == 'editor') {
              ++$ed_i;
              if ($ed_i < 31 && $crossRef->journal_title === null) {
                $this->addIfNew("editor$ed_i-last", formatSurname($author->surname));
                $this->addIfNew("editor$ed_i-first", formatForename($author->given_name));
              }
            } elseif ($author['contributor_role'] == 'author') {
              ++$au_i;
              $this->addIfNew("last$au_i", formatSurname($author->surname));
              $this->addIfNew("first$au_i", formatForename($author->given_name));
            }
          }
        }
        $this->addIfNew('isbn', $crossRef->isbn);
        $this->addIfNew('journal', $crossRef->journal_title);
        if ($crossRef->volume > 0) {
          $this->addIfNew('volume', $crossRef->volume);
        }

        if ((integer) $crossRef->issue > 1) {
          // "1" may refer to a journal without issue numbers,
          //  e.g. 10.1146/annurev.fl.23.010191.001111, as well as a genuine issue 1.  Best ignore.
          $this->addIfNew('issue', $crossRef->issue);
        }
        if ($this->blank("page")) {
          $this->addIfNew("pages", $crossRef->first_page
            . ($crossRef->last_page && ($crossRef->first_page != $crossRef->last_page)
              ? "-" . $crossRef->last_page//replaced by an endash later in script
               : ""));
        }

        echo " (ok)";
      } else {
        echo "\n - No CrossRef record found for doi '$doi'; marking as broken";
        $this->addIfNew('doi_brokendate', date('Y-m-d'));
      }
    }
  }

  public function expandByPubmed($force = false) {
    if (!$force && !$this->incomplete()) {
      return;
    }

    if ($pm = $this->get('pmid')) {
      $identifier = 'pmid';
    } else if ($pm = $this->get('pmc')) {
      $identifier = 'pmc';
    } else {
      return false;
    }

    global $html_output;
    echo "\n - Checking " . ($html_output ? '<a href="https://www.ncbi.nlm.nih.gov/pubmed/' . $pm . '" target="_blank">' : '') . strtoupper($identifier) . ' ' . $pm . ($html_output ? "</a>" : '') . ' for more details' . tag();
    $xml = simplexml_load_file("http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?tool=DOIbot&email=martins@gmail.com&db=" . (($identifier == "pmid") ? "pubmed" : "pmc") . "&id=$pm");
    // Debugging URL : view-source:http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&tool=DOIbot&email=martins@gmail.com&id=
    if (count($xml->DocSum->Item) > 0) {
      foreach ($xml->DocSum->Item as $item) {
        if (preg_match("~10\.\d{4}/[^\s\"']*~", $item, $match)) {
          $this->addIfNew('doi', $match[0]);
        }

        switch ($item["Name"]) {
        case "Title":$this->addIfNew('title', str_replace(array("[", "]"), "", (string) $item));
          break;case "PubDate":preg_match("~(\d+)\s*(\w*)~", $item, $match);
          $this->addIfNew('year', (string) $match[1]);
          break;case "FullJournalName":$this->addIfNew('journal', (string) $item);
          break;case "Volume":$this->addIfNew('volume', (string) $item);
          break;case "Issue":$this->addIfNew('issue', (string) $item);
          break;case "Pages":$this->addIfNew('pages', (string) $item);
          break;case "PmId":$this->addIfNew('pmid', (string) $item);
          break;case "AuthorList":
          $i = 0;
          foreach ($item->Item as $subItem) {
            $i++;
            if (authorIsHuman((string) $subItem)) {
              $jr_test = jrTest($subItem);
              $subItem = $jr_test[0];
              $junior = $jr_test[1];
              if (preg_match("~(.*) (\w+)$~", $subItem, $names)) {
                $first = trim(preg_replace('~(?<=[A-Z])([A-Z])~', ". $1", $names[2]));
                if (strpos($first, '.') && substr($first, -1) != '.') {
                  $first = $first . '.';
                }

                $this->addIfNew("author$i", $names[1] . $junior . ',' . $first);
              }
            } else {
              // We probably have a committee or similar.  Just use 'author$i'.
              $this->addIfNew("author$i", (string) $subItem);
            }
          }
          break;case "LangList":case 'ISSN':
          break;case "ArticleIds":
          foreach ($item->Item as $subItem) {
            switch ($subItem["Name"]) {
            case "pubmed":
              preg_match("~\d+~", (string) $subItem, $match);
              if ($this->addIfNew("pmid", $match[0])) {
                $this->expandByPubmed();
              }

              break; ### TODO PLACEHOLDER YOU ARE HERE CONTINUATION POINT ###
            case "pmc":
              preg_match("~\d+~", (string) $subItem, $match);
              $this->addIfNew('pmc', $match[0]);
              break;
            case "doi":case "pii":
            default:
              if (preg_match("~10\.\d{4}/[^\s\"']*~", (string) $subItem, $match)) {
                if ($this->addIfNew('doi', $match[0])) {
                  $this->expandByDoi();
                }

              }
              break;
            }
          }
          break;
        }
      }
    }

    if ($xml && $this->blank('doi')) {
      $this->getDoiFromCrossref();
    }

  }

  protected function useSici() {
    if (preg_match(siciRegExp, urldecode($this->parsedText()), $sici)) {
      if ($this->blank($journal, "issn")) {
        $this->set("issn", $sici[1]);
      }

      //if ($this->blank ("year") && $this->blank("month") && $sici[3]) $this->set("month", date("M", mktime(0, 0, 0, $sici[3], 1, 2005)));
      if ($this->blank("year")) {
        $this->set("year", $sici[2]);
      }

      //if ($this->blank("day") && is("month") && $sici[4]) set ("day", $sici[4]);
      if ($this->blank("volume")) {
        $this->set("volume", 1 * $sici[5]);
      }

      if ($this->blank("issue") && $this->blank('number') && $sici[6]) {
        $this->set("issue", 1 * $sici[6]);
      }

      if ($this->blank("pages", "page")) {
        $this->set("pages", 1 * $sici[7]);
      }

      return true;
    } else {
      return false;
    }

  }

  protected function queryCrossref($doi = false) {
    global $crossRefId;
    if (!$doi) {
      $doi = $this->get('doi');
    }

    if (!$doi) {
      warn('#TODO: crossref lookup with no doi');
    }

    $url = "http://www.crossref.org/openurl/?pid=$crossRefId&id=doi:$doi&noredirect=true";
    $xml = @simplexml_load_file($url);
    if ($xml) {
      $result = $xml->query_result->body->query;
      return ($result["status"] == "resolved") ? $result : false;
    } else {
      echo "\n   ! Error loading CrossRef file from DOI $doi!";
      return false;
    }
  }

  protected function expandByGoogleBooks() {
    $url = $this->get('url');
    if ($url && preg_match("~books\.google\.[\w\.]+/.*\bid=([\w\d\-]+)~", $url, $gid)) {
      if (strpos($url, "#")) {
        $url_parts = explode("#", $url);
        $url = $url_parts[0];
        $hash = "#" . $url_parts[1];
      }
      $url_parts = explode("&", str_replace("?", "&", $url));
      $url = "http://books.google.com/?id=" . $gid[1];
      foreach ($url_parts as $part) {
        $part_start = explode("=", $part);
        switch ($part_start[0]) {
        case "dq":case "pg":case "lpg":case "q":case "printsec":case "cd":case "vq":
          $url .= "&" . $part;
        // TODO: vq takes precedence over dq > q.  Only use one of the above.
        case "id":
          break; // Don't "remove redundant"
        case "as":case "useragent":case "as_brr":case "source":case "hl":
        case "ei":case "ots":case "sig":case "source":case "lr":
        case "as_brr":case "sa":case "oi":case "ct":case "client": // List of parameters known to be safe to remove
        default:
          echo "\n - $part";
          $removed_redundant++;
        }
      }
      if ($removed_redundant > 1) { // http:// is counted as 1 parameter
        $this->set('url', $url . $hash);
      }
      $this->googleBookDetails($gid[1]);
      return true;
    }
    return false;
  }

  protected function googleBookDetails($gid) {
    $google_book_url = "http://books.google.com/books/feeds/volumes/$gid";
    $simplified_xml = str_replace('http___//www.w3.org/2005/Atom', 'http://www.w3.org/2005/Atom',
      str_replace(":", "___", file_get_contents($google_book_url))
    );
    $xml = simplexml_load_string($simplified_xml);
    if ($xml->dc___title[1]) {
      $this->addIfNew("title", str_replace("___", ":", $xml->dc___title[0] . ": " . $xml->dc___title[1]));
    } else {
      $this->addIfNew("title", str_replace("___", ":", $xml->title));
    }
    // Possibly contains dud information on occasion
    // $this->add_if_new("publisher", str_replace("___", ":", $xml->dc___publisher));
    foreach ($xml->dc___identifier as $ident) {
      if (preg_match("~isbn.*?([\d\-]{9}[\d\-]+)~i", (string) $ident, $match)) {
        $isbn = $match[1];
      }
    }
    $this->addIfNew("isbn", $isbn);
    // Don't set 'pages' parameter, as this refers to the CITED pages, not the page count of the book.
    $i = null;
    if ($this->blank("editor") && $this->blank("editor1") && $this->blank("editor1-last") && $this->blank("editor-last") && $this->blank("author") && $this->blank("author1") && $this->blank("last") && $this->blank("last1") && $this->blank("publisher")) { // Too many errors in gBook database to add to existing data.   Only add if blank.
      foreach ($xml->dc___creator as $author) {
        $this->addIfNew("author" . ++$i, formatAuthor(str_replace("___", ":", $author)));
      }
    }
    $this->addIfNew("date", $xml->dc___date);
  }

  protected function findIsbn() {
    return false; #TODO restore this service.
    if ($this->blank('isbn') && $this->has('title')) {
      $title = trim($this->get('title'));
      $auth = trim($this->get('author') . $this->get('author1') . ' ' . $this->get('last') . $this->get('last1'));
      global $isbnKey, $over_isbn_limit;
      // TODO: implement over_isbn_limit based on &results=keystats in API
      if ($title && !$over_isbn_limit) {
        $xml = simplexml_load_file("http://isbndb.com/api/books.xml?access_key=$isbnKey&index1=combined&value1=" . urlencode($title . " " . $auth));
        print "\n\nhttp://isbndb.com/api/books.xml?access_key=$isbnKey&index1=combined&value1=" . urlencode($title . " " . $auth . "\n\n");
        if ($xml->BookList["total_results"] == 1) {
          return $this->set('isbn', (string) $xml->BookList->BookData["isbn"]);
        }

        if ($auth && $xml->BookList["total_results"] > 0) {
          return $this->set('isbn', (string) $xml->BookList->BookData["isbn"]);
        } else {
          return false;
        }

      }
    }
  }

  protected function findMoreAuthors() {
    /** If crossRef has only sent us one author, perhaps we can find their surname in association with other authors on the URL
     *   Send the URL and the first author's SURNAME ONLY as $a1
     *  The function will return an array of authors in the form $new_authors[3] = Author, The Third
     */
    if ($doi = $this->getWithoutComments('doi')) {
      $this->expandByDoi(true);
    }

    if ($this->get('pmid')) {
      $this->expandByPubmed(true);
    }

    $pages = $this->pageRange();
    $pages = $pages[0];
    if (preg_match("~\d\D+\d~", $pages)) {
      $new_pages = $pages;
    }

    if ($doi) {
      $url = "http://dx.doi.org/$doi";
    } else {
      $url = $this->get('url');
    }

    $stopRegexp = "[\n\(:]|\bAff"; // Not used currently - aff may not be necessary.
    if (!$url) {
      return null;
    }

    echo "\n  * Looking for more authors @ $url:";
    echo "\n   - Using meta tags...";
    $meta_tags = get_meta_tags($url);
    if ($meta_tags["citation_authors"]) {
      $new_authors = formatAuthors($meta_tags["citation_authors"], true);
    }

    global $slow_mode;
    if ($slow_mode && !$new_pages && !$new_authors) {
      echo "\n   - Now scraping web-page.";
      //Initiate cURL resource
      $ch = curl_init();
      curlSetup($ch, $url);

      curl_setopt($ch, CURLOPT_MAXREDIRS, 7); //This means we can't get stuck.
      if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 404) {
        echo "404 returned from URL.<br>";
      } elseif (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 501) {
        echo "501 returned from URL.<br>";
      } else {
        $source = str_ireplace(
          array('&nbsp;', '<p ', '<DIV '),
          array(' ', "\r\n    <p ", "\r\n    <DIV "),
          curl_exec($ch)
        ); // Spaces before '<p ' fix cases like 'Title' <p>authors</p> - otherwise 'Title' gets picked up as an author's initial.
        $source = preg_replace(
          "~<sup>.*</sup>~U",
          "",
          str_replace("\n", "\n  ", $source)
        );
        curl_close($ch);
        if (strlen($source) < 1280000) {

          // Pages - only check if we don't already have a range
          if (!$new_pages && preg_match("~^[\d\w]+$~", trim($pages), $page)) {
            // find an end page number first
            $firstPageForm = preg_replace('~d\?([^?]*)$~U', "d$1", preg_replace('~\d~', '\d?', preg_replace('~[a-z]~i', '[a-zA-Z]?', $page[0])));
            #echo "\n Searching for page number with form $firstPageForm:";
            if (preg_match("~{$page[0]}[^\d\w\.]{1,5}?(\d?$firstPageForm)~", trim($source), $pages)) { // 13 leaves enough to catch &nbsp;
              $new_pages = $page[0] . '-' . $pages[1];
              # echo " found range [$page[0] to $pages[1]]";
            } #else echo " not found.";
          }

          // Authors
          if (true || !$new_authors) {
            // Check dc.contributor, which isn't correctly handled by get_meta_tags
            if (preg_match_all("~\<meta name=\"dc.Contributor\" +content=\"([^\"]+)\"\>~U", $source, $authors)) {
              $new_authors = $authors[1];
            }
          }
        } else {
          echo "\n   x File size was too large. Abandoned.";
        }

      }
    }

    $count_new_authors = count($new_authors) - 1;
    if ($count_new_authors > 0) {
      $this->forget('author');
      for ($j = 0; $j < $count_new_authors; ++$j) {
        $au = explode(', ', $new_authors[$j - 1]);
        if ($au[1]) {
          $this->addIfNew('last' . $j, $au[0]);
          $this->addIfNew('first' . $j, preg_replace("~(\p{L})\p{L}*\.? ?~", "$1.", $au[1]));
          $this->forget('author' . $j);
        } else {
          if ($au[0]) {
            $this->addIfNew("author$j", $au[0]);
          }
        }
      }
    }
    if ($new_pages) {
      $this->set('pages', $new_pages);
      echo " [completed page range]";
    }
  }

  ### parameter processing
  protected function useUnnamedParams() {
    // Load list of parameters used in citation templates.
    //We generated this earlier in expandFns.php.  It is sorted from longest to shortest.
    global $parameter_list;
    if ($this->param) {
      $this->lowercaseParameters();
      $param_occurrences = array();
      $duplicated_parameters = array();
      $duplicate_identical = array();
      foreach ($this->param as $pointer => $par) {
        if ($par->param && ($duplicate_pos = $param_occurrences[$par->param]) !== null) {
          array_unshift($duplicated_parameters, $duplicate_pos);
          array_unshift($duplicate_identical, ($par->val == $this->param[$duplicate_pos]->val));
        }
        $param_occurrences[$par->param] = $pointer;
      }
      $n_dup_params = count($duplicated_parameters);
      for ($i = 0; $i < $n_dup_params; $i++) {
        if ($duplicate_identical[$i]) {
          echo "\n * Deleting identical duplicate of parameter: {$this->param[$duplicated_parameters[$i]]->param}\n";
          unset($this->param[$duplicated_parameters[$i]]);
        } else {
          $this->param[$duplicated_parameters[$i]]->param = str_replace('DUPLICATE_DUPLICATE_', 'DUPLICATE_', 'DUPLICATE_' . $this->param[$duplicated_parameters[$i]]->param);
          echo "\n * Marking duplicate parameter: {$duplicated_parameters[$i]->param}\n";
        }
      }
      foreach ($this->param as $iP => $p) {
        if (!empty($p->param)) {
          if (preg_match('~^\s*(https?://|www\.)\S+~', $p->param)) { # URL ending ~ xxx.com/?para=val
          $this->param[$iP]->val = $p->param . '=' . $p->val;
            $this->param[$iP]->param = 'url';
            if (stripos($p->val, 'books.google.') !== false) {
              $this->name = 'Cite book';
              $this->process();
            }
          } elseif ($p->param == 'doix') {
            global $dotEncode, $dotDecode;
            $this->param[$iP]->param = 'doi';
            $this->param[$iP]->val = str_replace($dotEncode, $dotDecode, $p->val);
          }
          continue;
        }
        $dat = $p->val;
        $endnote_test = explode("\n%", "\n" . $dat);
        if ($endnote_test[1]) {
          foreach ($endnote_test as $endnote_line) {
            switch ($endnote_line[0]) {
            case "A":$endnote_authors++;
              $endnote_parameter = "author$endnote_authors";
              break;
            case "D":$endnote_parameter = "date";
              break;
            case "I":$endnote_parameter = "publisher";
              break;
            case "C":$endnote_parameter = "location";
              break;
            case "J":$endnote_parameter = "journal";
              break;
            case "N":$endnote_parameter = "issue";
              break;
            case "P":$endnote_parameter = "pages";
              break;
            case "T":$endnote_parameter = "title";
              break;
            case "U":$endnote_parameter = "url";
              break;
            case "V":$endnote_parameter = "volume";
              break;
            case "@": // ISSN / ISBN
              if (preg_match("~@\s*[\d\-]{10,}~", $endnote_line)) {
                $endnote_parameter = "isbn";
                break;
              } else if (preg_match("~@\s*\d{4}\-?\d{4}~", $endnote_line)) {
                $endnote_parameter = "issn";
                break;
              } else {
                $endnote_parameter = false;
              }
            case "R": // Resource identifier... *may* be DOI but probably isn't always.
            case "8": // Date
            case "0": // Citation type
            case "X": // Abstract
            case "M": // Object identifier
              $dat = trim(str_replace("\n%$endnote_line", "", "\n" . $dat));
            default:
              $endnote_parameter = false;
            }
            if ($endnote_parameter && $this->blank($endnote_parameter)) {
              $to_add[$endnote_parameter] = substr($endnote_line, 1);
              $dat = trim(str_replace("\n%$endnote_line", "", "\n$dat"));
            }
          }
        }

        if (preg_match("~^TY\s+-\s+[A-Z]+~", $dat)) { // RIS formatted data:
          $ris = explode("\n", $dat);
          foreach ($ris as $ris_line) {
            $ris_part = explode(" - ", $ris_line . " ");
            switch (trim($ris_part[0])) {
            case "T1":
            case "TI":
              $ris_parameter = "title";
              break;
            case "AU":
              $ris_authors++;
              $ris_parameter = "author$ris_authors";
              $ris_part[1] = formatAuthor($ris_part[1]);
              break;
            case "Y1":
              $ris_parameter = "date";
              break;
            case "SP":
              $start_page = trim($ris_part[1]);
              $dat = trim(str_replace("\n$ris_line", "", "\n$dat"));
              break;
            case "EP":
              $end_page = trim($ris_part[1]);
              $dat = trim(str_replace("\n$ris_line", "", "\n$dat"));
              if_null_set("pages", $start_page . "-" . $end_page);
              break;
            case "DO":
              $ris_parameter = "doi";
              break;
            case "JO":
            case "JF":
              $ris_parameter = "journal";
              break;
            case "VL":
              $ris_parameter = "volume";
              break;
            case "IS":
              $ris_parameter = "issue";
              break;
            case "SN":
              $ris_parameter = "issn";
              break;
            case "UR":
              $ris_parameter = "url";
              break;
            case "PB":
              $ris_parameter = "publisher";
              break;
            case "M3":case "PY":case "N1":case "N2":case "ER":case "TY":case "KW":
              $dat = trim(str_replace("\n$ris_line", "", "\n$dat"));
            default:
              $ris_parameter = false;
            }
            unset($ris_part[0]);
            if ($ris_parameter
              && if_null_set($ris_parameter, trim(implode($ris_part)))
            ) {
              global $auto_summary;
              if (!strpos("Converted RIS citation to WP format", $auto_summary)) {
                $auto_summary .= "Converted RIS citation to WP format. ";
              }
              $dat = trim(str_replace("\n$ris_line", "", "\n$dat"));
            }
          }

        }
        if (preg_match('~^(https?://|www\.)\S+~', $dat, $match)) { #Takes priority over more tenative matches
        $this->set('url', $match[0]);
          $dat = str_replace($match[0], '', $dat);
        }
        if (preg_match_all("~(\w+)\.?[:\-\s]*([^\s;:,.]+)[;.,]*~", $dat, $match)) { #vol/page abbrev.
        foreach ($match[0] as $i => $oMatch) {
          switch (strtolower($match[1][$i])) {

          case "vol":case "v":case 'volume':
            $matched_parameter = "volume";
            break;
          case "no":case "number":case 'issue':case 'n':
            $matched_parameter = "issue";
            break;
          case 'pages':case 'pp':case 'pg':case 'pgs':case 'pag':
            $matched_parameter = "pages";
            break;
          case 'p':
            $matched_parameter = "page";
            break;
          default:
            $matched_parameter = null;
          }
          if ($matched_parameter) {
            $dat = trim(str_replace($oMatch, "", $dat));
            if ($i) {
              $this->addIfNew($matched_parameter, $match[2][$i]);
            } else {
              $this->param[$i]->param = $matched_parameter;
              $this->param[$i]->val = $match[2][0];
            }
          }
        }
        }
        if (preg_match("~(\d+)\s*(?:\((\d+)\))?\s*:\s*(\d+(?:\d\s*-\s*\d+))~", $dat, $match)) { //Vol(is):pp
          $this->addIfNew('volume', $match[1]);
          $this->addIfNew('issue', $match[2]);
          $this->addIfNew('pages', $match[3]);
          $dat = trim(str_replace($match[0], '', $dat));
        }
        if (preg_match("~\(?(1[89]\d\d|20\d\d)[.,;\)]*~", $dat, $match)) { #YYYY
        if ($this->blank('year')) {
          $this->set('year', $match[1]);
          $dat = trim(str_replace($match[0], '', $dat));
        }
        }

        $shortest = -1;
        foreach ($parameter_list as $parameter) {
          $para_len = strlen($parameter);
          if (substr(strtolower($dat), 0, $para_len) == $parameter) {
            $character_after_parameter = substr(trim(substr($dat, $para_len)), 0, 1);
            $parameter_value = ($character_after_parameter == "-" || $character_after_parameter == ":")
            ? substr(trim(substr($dat, $para_len)), 1) : substr($dat, $para_len);
            $this->param[$iP]->param = $parameter;
            $this->param[$iP]->val = $parameter_value;
            break;
          }
          $test_dat = preg_replace("~\d~", "_$0",
            preg_replace("~[ -+].*$~", "", substr(mb_strtolower($dat), 0, $para_len)));
          if ($para_len < 3) {
            break;
          }
          // minimum length to avoid false positives
          if (preg_match("~\d~", $parameter)) {
            $lev = levenshtein($test_dat, preg_replace("~\d~", "_$0", $parameter));
            $para_len++;
          } else {
            $lev = levenshtein($test_dat, $parameter);
          }
          if ($lev == 0) {
            $closest = $parameter;
            $shortest = 0;
            break;
          }
          // Strict inequality as we want to favour the longest match possible
          if ($lev < $shortest || $shortest < 0) {
            $comp = $closest;
            $closest = $parameter;
            $shortish = $shortest;
            $shortest = $lev;
          } elseif ($lev < $shortish) {
            // Keep track of the second shortest result, to ensure that our chosen parameter is an out and out winner
            $shortish = $lev;
            $comp = $parameter;
          }

        }

        if ($shortest < 3
          && strlen($test_dat > 0)
          && similar_text($shortest, $test_dat) / strlen($test_dat) > 0.4
          && ($shortest + 1 < $shortish // No close competitor
             || $shortest / $shortish <= 2 / 3
            || strlen($closest) > strlen($comp)
          )
        ) {
          // remove leading spaces or hyphens (which may have been typoed for an equals)
          if (preg_match("~^[ -+]*(.+)~", substr($dat, strlen($closest)), $match)) {
            $this->add($closest, $match[1]/* . " [$shortest / $comp = $shortish]"*/);
          }
        } elseif (preg_match("~(?!<\d)(\d{10}|\d{13})(?!\d)~", str_replace(array(" ", "-"), "", $dat), $match)) {
          // Is it a number formatted like an ISBN?
          $this->add('isbn', $match[1]);
          $pAll = "";
        } else {
          // Extract whatever appears before the first space, and compare it to common parameters
          $pAll = explode(" ", trim($dat));
          $p1 = mb_strtolower($pAll[0]);
          switch ($p1) {
          case "volume":case "vol":
          case "pages":case "page":
          case "year":case "date":
          case "title":
          case "authors":case "author":
          case "issue":
          case "journal":
          case "accessdate":
          case "archiveurl":
          case "archivedate":
          case "format":
          case "url":
            if ($this->blank($p1)) {
              unset($pAll[0]);
              $this->param[$iP]->param = $p1;
              $this->param[$iP]->val = implode(" ", $pAll);
            }
            break;
          case "issues":
            if ($this->blank($p1)) {
              unset($pAll[0]);
              $this->param[$iP]->param = 'issue';
              $this->param[$iP]->val = implode(" ", $pAll);
            }
            break;
          case "access date":
            if ($this->blank($p1)) {
              unset($pAll[0]);
              $this->param[$iP]->param = 'accessdate';
              $this->param[$iP]->val = implode(" ", $pAll);
            }
            break;
          }
        }
        if (!trim($dat)) {
          unset($this->param[$iP]);
        }

      }
    }
  }

  protected function idToParam() {
    $id = $this->get('id');
    if (trim($id)) {
      echo ("\n - Trying to convert ID parameter to parameterized identifiers.");
    } else {
      return false;
    }
    if (preg_match("~\b(PMID|DOI|ISBN|ISSN|ARXIV|LCCN)[\s:]*(\d[\d\s\-]*[^\s\}\{\|]*)~iu", $id, $match)) {
      $this->addIfNew(strtolower($match[1]), $match[2]);
      $id = str_replace($match[0], '', $id);
    }
    preg_match_all("~\{\{(?P<content>(?:[^\}]|\}[^\}])+?)\}\}[,. ]*~", $id, $match);
    foreach ($match["content"] as $i => $content) {
      $content = explode(PIPE_PLACEHOLDER, $content);
      unset($parameters);
      $j = 0;
      foreach ($content as $fragment) {
        $content[$j++] = $fragment;
        $para = explode("=", $fragment);
        if (trim($para[1])) {
          $parameters[trim($para[0])] = trim($para[1]);
        }
      }
      switch (strtolower(trim($content[0]))) {
      case "arxiv":
        array_shift($content);
        if ($parameters["id"]) {
          $this->addIfNew("arxiv", ($parameters["archive"] ? trim($parameters["archive"]) . "/" : "") . trim($parameters["id"]));
        } else if ($content[1]) {
          $this->addIfNew("arxiv", trim($content[0]) . "/" . trim($content[1]));
        } else {
          $this->addIfNew("arxiv", implode(PIPE_PLACEHOLDER, $content));
        }
        $id = str_replace($match[0][$i], "", $id);
        break;
      case "lccn":
        $this->addIfNew("lccn", trim($content[1]) . $content[3]);
        $id = str_replace($match[0][$i], "", $id);
        break;
      case "rfcurl":
        $identifier_parameter = "rfc";
      case "asin":
        if ($parameters["country"]) {
          echo "\n    - {{ASIN}} country parameter not supported: can't convert.";
          break;
        }
      case "oclc":
        if ($content[2]) {
          echo "\n    - {{OCLC}} has multiple parameters: can't convert.";
          break;
        }
      case "ol":
        if ($parameters["author"]) {
          echo "\n    - {{OL}} author parameter not supported: can't convert.";
          break;
        }
      case "bibcode":
      case "doi":
      case "isbn":
      case "issn":
      case "jfm":
      case "jstor":
        if ($parameters["sici"] || $parameters["issn"]) {
          echo "\n    - {{JSTOR}} named parameters are not supported: can't convert.";
          break;
        }
      case "mr":
      case "osti":
      case "pmid":
      case "pmc":
      case "ssrn":
      case "zbl":
        if ($identifier_parameter) {
          array_shift($content);
        }
        $this->addIfNew($identifier_parameter ? $identifier_parameter : strtolower(trim(array_shift($content))), $parameters["id"] ? $parameters["id"] : $content[0]);
        $identifier_parameter = null;
        $id = str_replace($match[0][$i], "", $id);
        break;
      default:
        echo "\n    - No match found for $content[0].";
      }
    }
    if (trim($id)) {
      $this->set('id', $id);
    } else {
      $this->forget('id');
    }

  }

  protected function correctParamSpelling() {
    // check each parameter name against the list of accepted names (loaded in expand.php).
    // It will correct any that appear to be mistyped.
    global $parameter_list, $common_mistakes;
    $mistake_corrections = array_values($common_mistakes);
    $mistake_keys = array_keys($common_mistakes);
    if ($this->param) {
      foreach ($this->param as $p) {
        $parameters_used[] = $p->param;
      }
    }

    $unused_parameters = ($parameters_used ? array_diff($parameter_list, $parameters_used) : $parameter_list);

    $i = 0;
    foreach ($this->param as $p) {
      ++$i;
      if ((strlen($p->param) > 0) && !in_array($p->param, $parameter_list)) {
        //FIXME this is making bad "corrections", not distinguishing between coauthor/s
        if (substr($p->param, 0, 8) == "coauthor") {
          echo "\n  ! The coauthor parameter is deprecated";
          if ($this->has('authors')) {
            echo " please replace this manually.";
          } else {
            $p->param = 'authors';
          }
        } else {
          echo "\n  *  Unrecognised parameter {$p->param} ";
          $mistake_id = array_search($p->param, $mistake_keys);
          if ($mistake_id) {
            // Check for common mistakes.  This will over-ride anything found by levenshtein: important for "editor1link" !-> "editor-link".
            $p->param = $mistake_corrections[$mistake_id];
            echo 'replaced with ' . $mistake_corrections[$mistake_id] . ' (common mistakes list)';
            continue;
          }
          $p->param = preg_replace('~author(\d+)-(la|fir)st~', "$2st$1", $p->param);
          $p->param = preg_replace('~surname\-?_?(\d+)~', "last$1", $p->param);
          $p->param = preg_replace('~(?:forename|initials?)\-?_?(\d+)~', "first$1", $p->param);

          // Check the parameter list to find a likely replacement
          $shortest = -1;
          foreach ($unused_parameters as $parameter) {
            $lev = levenshtein($p->param, $parameter, 5, 4, 6);
            // Strict inequality as we want to favour the longest match possible
            if ($lev < $shortest || $shortest < 0) {
              $comp = $closest;
              $closest = $parameter;
              $shortish = $shortest;
              $shortest = $lev;
            }
            // Keep track of the second-shortest result, to ensure that our chosen parameter is an out and out winner
            else if ($lev < $shortish) {
              $shortish = $lev;
              $comp = $parameter;
            }
          }
          $str_len = strlen($p->param);

          // Account for short words...
          if ($str_len < 4) {
            $shortest *= ($str_len / (similar_text($p->param, $closest) ? similar_text($p->param, $closest) : 0.001));
            $shortish *= ($str_len / (similar_text($p->param, $comp) ? similar_text($p->param, $comp) : 0.001));
          }
          if ($shortest < 12 && $shortest < $shortish) {
            $p->param = $closest;
            echo "replaced with $closest (likelihood " . (12 - $shortest) . "/12)";
          } else {
            $similarity = similar_text($p->param, $closest) / strlen($p->param);
            if ($similarity > 0.6) {
              $p->param = $closest;
              echo "replaced with $closest (similarity " . round(12 * $similarity, 1) . "/12)";
            } else {
              echo "could not be replaced with confidence.  Please check the citation yourself.";
            }
          }
        }
      }
    }
  }

  public function removeNonAscii() {
    for ($i = 0; $i < count($this->param); $i++) {
      $this->param[$i]->val = preg_replace('/[^\x20-\x7e]/', '', $this->param[$i]->val); // Remove illegal non-ASCII characters such as invisible spaces
    }
  }

  protected function joinParams() {
    $ret = '';
    if ($this->param) {
      foreach ($this->param as $p) {
        $ret .= '|' . $p->parsedText();
      }
    }

    return $ret;
  }

  public function wikiname() {
    return trim(mb_strtolower(str_replace('_', ' ', $this->name)));
  }

  ### Tidying and formatting
  protected function tidy() {
    if ($this->added('title')) {
      $this->formatTitle();
    } else if ($this->isModified() && $this->get('title')) {
      $this->set('title', straighten_quotes((mb_substr($this->get('title'), -1) == ".") ? mb_substr($this->get('title'), 0, -1) : $this->get('title')));
    }

    if ($this->blank(array('date', 'year')) && $this->has('origyear')) {
      $this->rename('origyear', 'year');
    }

    if (!($authors = $this->get('authors'))) {
      $authors = $this->get('author'); # Order _should_ be irrelevant as only one will be set... but prefer 'authors' if not.
    }

    if (preg_match('~([,;])\s+\[\[|\]\]([;,])~', $authors, $match)) {
      $this->addIfNew('author-separator', $match[1] ? $match[1] : $match[2]);
      $new_authors = explode($match[1] . $match[2], $authors);
      $this->forget('author');
      $this->forget('authors');
      for ($i = 0; $i < count($new_authors); $i++) {
        $this->addIfNew("author" . ($i + 1), trim($new_authors[$i]));
      }
    }

    if ($this->param) {
      foreach ($this->param as $p) {
        preg_match('~(\D+)(\d*)~', $p->param, $pmatch);
        switch ($pmatch[1]) {
        case 'author':case 'authors':case 'last':case 'surname':
          if ($pmatch[2]) {
            if (preg_match("~\[\[(([^\|]+)\|)?([^\]]+)\]?\]?~", $p->val, $match)) {
              $to_add['authorlink' . $pmatch[2]] = ucfirst($match[2] ? $match[2] : $match[3]);
              $p->val = $match[3];
              echo "\n   ~ Dissecting authorlink" . tag();
            }
            $translator_regexp = "~\b([Tt]r(ans(lat...?(by)?)?)?\.)\s([\w\p{L}\p{M}\s]+)$~u";
            if (preg_match($translator_regexp, trim($p->val), $match)) {
              $others = "{$match[1]} {$match[5]}";
              $p->val = preg_replace($translator_regexp, "", $p->val);
            }
          }
          break;
        case 'journal':case 'periodical':$p->val = capitalize_title($p->val, false, false);
          break;
        case 'edition':$p->val = preg_replace("~\s+ed(ition)?\.?\s*$~i", "", $p->val);
          break; // Don't want 'Edition ed.'
        case 'pages':case 'page':case 'issue':case 'year':
          if (!preg_match("~^[A-Za-z ]+\-~", $p->val) && mb_ereg(to_en_dash, $p->val)) {
            $this->mod_dashes = true;
            echo ("\n   ~ Upgrading to en-dash in" . $p->param . tag());
            $p->val = mb_ereg_replace(to_en_dash, en_dash, $p->val);
          }
          break;
        }
      }
    }

    if ($to_add) {
      foreach ($to_add as $key => $val) {
        $this->addIfNew($key, $val);
      }
    }

    if ($others) {
      if ($this->has('others')) {
        $this->appendto('others', '; ' . $others);
      } else {
        $this->set('others', $others);
      }

    }

    if ($this->numberOfAuthors() == 9 && $this->displayAuthors() == false) {
      $this->displayAuthors(8); // So that displayed output does not change
      echo "\n * Exactly 9 authors; look for more [... tidy]:";
      $this->findMoreAuthors();
      echo "now we have " . $this->numberOfAuthors() . "\n";
      if ($this->numberOfAuthors() == 9) {
        $this->displayAuthors(9);
      }
      // Better display an author's name than 'et al' when the et al only hides 1 author!
    }

    if ($this->added('journal') || $journal && $this->added('issn')) {
      $this->forget('issn');
    }

    if ($journal) {
      $volume = $this->get('volume');
      if (($this->has('doi') || $this->has('issn'))) {
        $this->forget('publisher', 'tidy');
      }

      // Replace "volume = B 120" with "series=VB, volume = 120
      if (preg_match("~^([A-J])(?!\w)\d*\d+~u", $volume, $match) && mb_substr(trim($journal), -2) != " $match[1]") {
        $journal .= " $match[1]";
        $this->set('volume', trim(mb_substr($volume, mb_strlen($match[1]))));
      }
      $this->set('journal', $journal);
      // Clean up after errors in publishers' databases
      if (0 === strpos(trim($journal), "BMC ") && $this->pageRange()) {
        $this->forget('issue');
        echo "\n   - dropping issue number (BMC journals only have page numbers)";
      }
    }

    // Remove leading zeroes
    if (!$this->blank('issue') && $this->blank('number')) {
      $new_issue = preg_replace('~^0+~', '', $this->get('issue'));
      if ($new_issue) {
        $this->set('issue', $new_issue);
      } else {
        $this->forget('issue');
      }

    }
    switch (strtolower(trim($this->get('quotes')))) {
    case 'yes':case 'y':case 'true':case 'no':case 'n':case 'false':$this->forget('quotes');
    }

    if ($this->get('doi') == "10.1267/science.040579197") {
      $this->forget('doi');
    }
    // This is a bogus DOI from the PMID example file

    /*/ If we have any unused data, check to see if any is redundant!
    if (is("unused_data")) {
    $freeDat = explode("|", trim($this->get('unused_data')));
    unset($this->get('unused_data');
    foreach ($freeDat as $dat) {
    $eraseThis = false;
    foreach ($p as $oP) {
    similar_text(mb_strtolower($oP[0]), mb_strtolower($dat), $percentSim);
    if ($percentSim >= 85)
    $eraseThis = true;
    }
    if (!$eraseThis)
    $this->!et('unused_data') .= "|" . $dat;
    }
    if (trim(str_replace("|", "", $this->!et('unused_data'))) == "")
    unset($this->!et('unused_data');
    else {
    if (substr(trim($this->!et('unused_data')), 0, 1) == "|")
    $this->!et('unused_data') = substr(trim($this->!et('unused_data')), 1);
    }
    }*/
    if ($this->has('accessdate') && $this->lacks('url')) {
      $this->forget('accessdate');
    }

  }

  protected function formatTitle($title = false) {
    if (!$title) {
      $title = $this->get('title');
    }

    $this->set('title', format_title_text($title)); // order IS important!
  }

  protected function sanitizeDoi($doi = false) {
    if (!$doi) {
      $doi = $this->get('doi');
      if (!$doi) {
        return false;
      }

    }
    global $pcEncode, $pcDecode, $spurious_whitespace;
    $this->set('doi', str_replace($spurious_whitespace, '', str_replace($pcEncode, $pcDecode, str_replace(' ', '+', trim(urldecode($doi))))));
    return true;
  }

  protected function verifyDoi() {
    $doi = $this->getWithoutComments('doi');
    if (!$doi) {
      return null;
    }

    // DOI not correctly formatted
    switch (substr($doi, -1)) {
    case ".":
      // Missing a terminal 'x'?
      $trial[] = $doi . "x";
    case ",":case ";":
      // Or is this extra punctuation copied in?
      $trial[] = substr($doi, 0, -1);
    }
    if (substr($doi, 0, 3) != "10.") {
      $trial[] = $doi;
    }
    if (preg_match("~^(.+)(10\.\d{4}/.+)~", trim($doi), $match)) {
      $trial[] = $match[1];
      $trial[] = $match[2];
    }
    $replacements = array("&lt;" => "<", "&gt;" => ">");
    if (preg_match("~&[lg]t;~", $doi)) {
      $trial[] = str_replace(array_keys($replacements), $replacements, $doi);
    }
    if ($trial) {
      foreach ($trial as $try) {
        // Check that it begins with 10.
        if (preg_match("~[^/]*(\d{4}/.+)$~", $try, $match)) {
          $try = "10." . $match[1];
        }

        if ($this->expandByDoi($try)) {$this->set('doi', $try);
          $doi = $try;}
      }
    }

    echo "\n   . Checking that DOI $doi is operational..." . tag();
    if ($this->queryCrossref() === false) {
      $this->set("doi_brokendate", date("Y-m-d"));
      echo "\n   ! Broken doi: $doi";
      return false;
    } else {
      $this->forget('doi_brokendate');
      $this->forget('doi_inactivedate');
      echo ' DOI ok.';
      return true;
    }
  }

  public function checkUrl() {
    // Check that the URL functions, and mark as dead if not.
    /*  Disable; to re-enable, we should log possible 404s and check back later.
   * Also, dead-link notifications should be placed ''after'', not within, the template.

  if (!is("format") && is("url") && !is("accessdate") && !is("archivedate") && !is("archiveurl"))
  {
  echo "\n - Checking that URL is live...";
  $formatSet = isset($p["format"]);
  $p["format"][0] = assessUrl($p["url"][0]);
  if (!$formatSet && trim($p["format"][0]) == "") {
  unset($p["format"]);
  }
  echo "Done" , is("format")?" ({$p["format"][0]})":"" , ".</p>";
  }*/

  }

# FIXME this maybe shouldn't exist at all?
  protected function handleEtAl() {
    global $author_parameters;
    foreach ($author_parameters as $i => $group) {
      foreach ($group as $param) {
        if (strpos($this->get($param), 'et al')) {
          $val_base = preg_replace("~,?\s*'*et al['.]*~", '', $this->get($param));
          if ($i == 1) {
            // then there's scope for "Smith, AB; Peters, Q.R. et al"

            // then we (probably) have a list of authors joined by commas in our first parameter
            if (under_two_authors($val_base)) {
              if ($param == 'authors') {
                $this->rename('authors', 'author');
              }
              //FIXME still not sure whether this is right
              echo "\n under two authors according to et al. fn\n"; //FIXME debug
            }
            $this->set($param, $val_base);
          }
          if (trim($val_base) == "") {
            $this->forget($param);
          }

//          $this->add_if_new('author' . ($i + 1), 'and others'); //FIXME: this may be the thing that's overwriting author parameters
          $this->addIfNew('displayauthors', $i);
        }
      }
    }
  }

  public function citeDoiFormat() {
    global $dotEncode, $dotDecode;
    echo "\n   * Cite Doi formatting... " . tag();
    $this->tidy();
    $doi = $this->get('doi');

    // If we only have the first author, look for more!
    if ($this->blank('coauthors')
      && $this->blank('author2')
      && $this->blank('last2')
      && $doi) {
      echo "\n     - Looking for co-authors & page numbers...";
      $this->findMoreAuthors();
    }
    for ($i = 1; $i < 100; $i++) {
      foreach (array("author", "last", "first", 'editor') as $param) {
        if ($this->get($param . $i) == "") {
          $this->forget($param . $i);
        }
      }
    }
    // Check that DOI hasn't been urlencoded.  Note that the doix parameter is decoded and used in step 1.
    if (strpos($doi, ".2F") && !strpos($doi, "/")) {
      $this->set('doi', str_replace($dotEncode, $dotDecode, $doi));
    }

    // Cycle through authors
    for ($i = null; $i < 100; $i++) {
      if (strpos(($au = $this->get("author$i")), ', ')) {
        // $au is an array with two parameters: the surname [0] and forename [1].
        $au = explode(', ', $au);
        $this->forget("author$i");
        $this->set("author$i", formatSurname($au[0])); // i.e. drop the forename; this is safe in $au[1]
      } else if ($this->get("first$i")) {
        $au[1] = $this->get("first$i");
      } else {
        unset($au);
      }
      if ($au[1]) {
        if ($au[1] == mb_strtoupper($au[1]) && mb_strlen($au[1]) < 4) {
          // Try to separate Smith, LE for Smith, Le.
          $au[1] = preg_replace("~([A-Z])[\s\.]*~u", "$1.", $au[1]);
        }
        if (trim(mb_strtoupper(preg_replace("~(\w)[a-z]*.? ?~u", "$1. ", trim($au[1]))))
          != trim($this->get("first$i"))) {
          // Don't try to modify if we don't need to change
          $this->set("first$i", mb_strtoupper(preg_replace("~(\w)[a-z]*.? ?~u", "$1. ", trim($au[1])))); // Replace names with initials; beware hyphenated names!
        }
        $para_pos = $this->getParamPosition("first$i");
        if ($para_pos > 1) {
          $this->param[$this->getParamPosition("first$i") - 1]->post = str_replace(array("\r", "\n"), " ", $this->param[$this->getParamPosition("first$i") - 1]->post); // Save space by including on same line as previous parameter
        }
      }
    }
    if ($pp_start = $this->get('pages')) {
      // Format pages to R100-R102 format
      if (preg_match("~([A-Za-z0-9]+)[^A-Za-z0-9]+([A-Za-z0-9]+)~", $pp_start, $pp)) {
        if (strlen($pp[1]) > strlen($pp[2])) {
          // The end page range must be truncated
          $this->set('pages', str_replace("!!!DELETE!!!", "", preg_replace("~([A-Za-z0-9]+[^A-Za-z0-9]+)[A-Za-z0-9]+~",
            ("$1!!!DELETE!!!" . substr($pp[1], 0, strlen($pp[1]) - strlen($pp[2])) . $pp[2])
            , $pp_start)));
        }
      }
    }
  }

  // Retrieve parameters
  public function displayAuthors($newval = false) {
    if ($newval && is_int($newval)) {
      $this->forget('displayauthors');
      echo "\n ~ Seting display-authors to $newval" . tag();
      $this->set('display-authors', $newval);
    }
    if (($da = $this->get('display-authors')) === null) {
      $da = $this->get('displayauthors');
    }

    return is_int(1 * $da) ? $da : false;
  }

  public function numberOfAuthors() {
    $max = 0;
    if ($this->param) {
      foreach ($this->param as $p) {
        if (preg_match('~(?:author|last|first|forename|initials|surname)(\d+)~', $p->param, $matches)) {
          $max = max($matches[1], $max);
        }

      }
    }

    return $max;
  }

  public function firstAuthor() {
    // Fetch the surname of the first author only
    preg_match("~[^.,;\s]{2,}~u", implode(' ',
      array($this->get('author'), $this->get('author1'), $this->get('last'), $this->get('last1')))
      , $first_author);
    return $first_author[0];
  }

  public function page() {return ($page = $this->get('pages') ? $page : $this->get('page'));}

  public function name() {return trim($this->name);}

  public function pageRange() {
    preg_match("~(\w?\w?\d+\w?\w?)(?:\D+(\w?\w?\d+\w?\w?))?~", $this->page(), $pagenos);
    return $pagenos;
  }

  // Amend parameters
  public function rename($old, $new, $new_value = false) {
    foreach ($this->param as $p) {
      if ($p->param == $old) {
        $p->param = $new;
        if ($new_value) {
          $p->val = $new_value;
        }

      }
    }
  }

  public function get($name) {
    if ($this->param) {
      foreach ($this->param as $p) {
        if ($p->param == $name) {
          return $p->val;
        }

      }
    }

    return null;
  }
  public function getWithoutComments($name) {
    $ret = preg_replace('~<!--.*?-->~su', '', $this->get($name));
    return (trim($ret) ? $ret : false);
  }

  protected function getParamPosition($needle) {
    if ($this->param) {
      foreach ($this->param as $i => $p) {
        if ($p->param == $needle) {
          return $i;
        }

      }
    }

    return null;
  }

  public function has($par) {return (bool) strlen($this->get($par));}
  public function lacks($par) {return !$this->has($par);}

  public function add($par, $val) {
    echo "\n   + Adding $par" . tag();
    return $this->set($par, $val);
  }
  public function set($par, $val) {
    if (($pos = $this->getParamPosition($par)) !== null) {
      return $this->param[$pos]->val = $val;
    }

    if ($this->param[0]) {
      $p = new Parameter;
      $p->parseText($this->param[$this->param[1] ? 1 : 0]->parsedText()); // Use second param if present, in case first pair is last1 = Smith | first1 = J.\n
    } else {
      $p = new Parameter;
      $p->parseText('| param = val');
    }
    $p->param = $par;
    $p->val = $val;
    $insert_after = prior_parameters($par);
    foreach (array_reverse($insert_after) as $after) {
      if (($insert_pos = $this->getParamPosition($after)) !== null) {
        $this->param = array_merge(array_slice($this->param, 0, $insert_pos + 1), array($p), array_slice($this->param, $insert_pos + 1));
        return true;
      }
    }
    $this->param[] = $p;
    return true;
  }

  public function appendto($par, $val) {
    if ($pos = $this->getParamPosition($par)) {
      return $this->param[$pos]->val = $this->param[$pos]->val . $val;
    } else {
      return $this->set($par, $val);
    }

  }

  public function forget($par) {
    $pos = $this->getParamPosition($par);
    if ($pos !== null) {
      echo "\n   - Dropping redundant parameter $par" . tag();
      unset($this->param[$pos]);
    }
  }

  // Record modifications
  protected function modified($param, $type = 'modifications') {
    switch ($type) {
    case '+':$type = 'additions';
      break;
    case '-':$type = 'deletions';
      break;
    case '~':$type = 'changeonly';
      break;
    default:$type = 'modifications';
    }
    return in_array($param, $this->modifications($type));
  }
  protected function added($param) {return $this->modified($param, '+');}

  public function modifications($type = 'all') {
    if ($this->param) {
      foreach ($this->param as $p) {
        $new[$p->param] = $p->val;
      }
    } else {
      $new = array();
    }

    $old = ($this->initial_param) ? $this->initial_param : array();
    if ($new) {
      if ($old) {
        $ret['modifications'] = array_keys(array_diff_assoc($new, $old));
        $ret['additions'] = array_diff(array_keys($new), array_keys($old));
        $ret['deletions'] = array_diff(array_keys($old), array_keys($new));
        $ret['changeonly'] = array_diff($ret['modifications'], $ret['additions']);
      } else {
        $ret['additions'] = array_keys($new);
        $ret['modifications'] = array_keys($new);
      }
    }
    $ret['dashes'] = $this->mod_dashes;
    if (in_array($type, array_keys($ret))) {
      return $ret[$type];
    }

    return $ret;
  }

  public function isModified() {
    return (bool) count($this->modifications('modifications'));
  }

  // Parse initial text
  public function parsedText() {
    return ($this->add_ref_tags ? '<ref>' : '') . '{{' . $this->name . $this->joinParams() . '}}' . ($this->add_ref_tags ? '</ref>' : '');
  }
}
