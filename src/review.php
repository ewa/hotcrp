<?php
// review.php -- HotCRP helper class for producing review forms and tables
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// JSON schema for settings["review_form"]:
// {FIELD:{"name":NAME,"description":DESCRIPTION,"position":POSITION,
//         "display_space":ROWS,"visibility":VISIBILITY,
//         "options":[DESCRIPTION,...],"option_letter":LEVELCHAR}}

class ReviewFieldInfo {
    public $id;
    public $short_id;
    public $has_options;
    public $main_storage;
    public $json_storage;

    function __construct($id, $short_id, $has_options, $main_storage, $json_storage) {
        $this->id = $id;
        $this->short_id = $short_id;
        $this->has_options = $has_options;
        $this->main_storage = $main_storage;
        $this->json_storage = $json_storage;
    }
}

class ReviewField implements Abbreviator, JsonSerializable {
    const VALUE_NONE = 0;
    const VALUE_SC = 1;
    const VALUE_REV_NUM = 2;
    const VALUE_STRING = 4;

    public $id;
    public $short_id;
    public $conf;
    public $name;
    public $name_html;
    public $description;
    private $abbreviation;
    public $has_options;
    public $options = array();
    public $option_letter = false;
    public $display_space;
    public $view_score;
    public $displayed = false;
    public $display_order;
    public $option_class_prefix = "sv";
    public $round_mask = 0;
    public $allow_empty = false;
    public $main_storage;
    public $json_storage;
    private $_typical_score = false;

    static private $view_score_map = [
        "secret" => VIEWSCORE_ADMINONLY, "admin" => VIEWSCORE_REVIEWERONLY,
        "pc" => VIEWSCORE_PC,
        "audec" => VIEWSCORE_AUTHORDEC, "authordec" => VIEWSCORE_AUTHORDEC,
        "au" => VIEWSCORE_AUTHOR, "author" => VIEWSCORE_AUTHOR
    ];
    // Hard-code the database's `view_score` values as of January 2016
    static private $view_score_upgrade_map = [
        "-2" => "secret", "-1" => "admin", "0" => "pc", "1" => "au"
    ];
    static private $view_score_rmap = [
        VIEWSCORE_ADMINONLY => "secret", VIEWSCORE_REVIEWERONLY => "admin",
        VIEWSCORE_PC => "pc", VIEWSCORE_AUTHORDEC => "audec",
        VIEWSCORE_AUTHOR => "au"
    ];

    function __construct(ReviewFieldInfo $finfo, Conf $conf) {
        $this->id = $finfo->id;
        $this->short_id = $finfo->short_id;
        $this->has_options = $finfo->has_options;
        $this->main_storage = $finfo->main_storage;
        $this->json_storage = $finfo->json_storage;
        $this->conf = $conf;
    }
    static function make_template($has_options, Conf $conf) {
        $id = $has_options ? "s00" : "t00";
        return new ReviewField(new ReviewFieldInfo($id, $id, $has_options, null, null), $conf);
    }

    function assign($j) {
        $this->name = (get($j, "name") ? : "Field name");
        $this->name_html = htmlspecialchars($this->name);
        $this->description = (get($j, "description") ? : "");
        $this->display_space = get_i($j, "display_space");
        if (!$this->has_options && $this->display_space < 3)
            $this->display_space = 3;
        $vis = get($j, "visibility");
        if ($vis === null) {
            $vis = get($j, "view_score");
            if (is_int($vis))
                $vis = self::$view_score_upgrade_map[$vis];
        }
        $this->view_score = VIEWSCORE_PC;
        if (is_string($vis) && isset(self::$view_score_map[$vis]))
            $this->view_score = self::$view_score_map[$vis];
        if (get($j, "position")) {
            $this->displayed = true;
            $this->display_order = $j->position;
        } else
            $this->displayed = $this->display_order = false;
        $this->round_mask = get_i($j, "round_mask");
        if ($this->has_options) {
            $options = get($j, "options") ? : array();
            $ol = get($j, "option_letter");
            if ($ol && ctype_alpha($ol) && strlen($ol) == 1)
                $this->option_letter = ord($ol) + count($options);
            else if ($ol && (is_int($ol) || ctype_digit($ol)))
                $this->option_letter = (int) $ol;
            else
                $this->option_letter = false;
            $this->options = array();
            if ($this->option_letter) {
                foreach (array_reverse($options, true) as $i => $n)
                    $this->options[chr($this->option_letter - $i - 1)] = $n;
            } else {
                foreach ($options as $i => $n)
                    $this->options[$i + 1] = $n;
            }
            if (($p = get($j, "option_class_prefix")))
                $this->option_class_prefix = $p;
            if (get($j, "allow_empty"))
                $this->allow_empty = true;
        }
        $this->_typical_score = false;
    }

    function unparse_json($for_settings = false) {
        $j = (object) array("name" => $this->name);
        if ($this->description)
            $j->description = $this->description;
        if (!$this->has_options && $this->display_space > 3)
            $j->display_space = $this->display_space;
        if ($this->displayed)
            $j->position = $this->display_order;
        $j->visibility = $this->unparse_visibility();
        if ($this->has_options) {
            $j->options = array();
            foreach ($this->options as $otext)
                $j->options[] = $otext;
            if ($this->option_letter) {
                $j->options = array_reverse($j->options);
                $j->option_letter = chr($this->option_letter - count($j->options));
            }
            if ($this->option_class_prefix !== "sv")
                $j->option_class_prefix = $this->option_class_prefix;
            if ($this->allow_empty)
                $j->allow_empty = true;
        }
        if ($this->round_mask && $for_settings)
            $j->round_mask = $this->round_mask;
        else if ($this->round_mask) {
            $j->round_list = array();
            foreach ($this->conf->round_list() as $i => $round_name)
                if ($this->round_mask & (1 << $i))
                    $j->round_list[] = $i ? $round_name : "unnamed";
        }
        return $j;
    }
    function jsonSerialize() {
        return $this->unparse_json();
    }

    static function unparse_visibility_value($vs) {
        if (isset(self::$view_score_rmap[$vs]))
            return self::$view_score_rmap[$vs];
        else
            return $vs;
    }

    function unparse_visibility() {
        return self::unparse_visibility_value($this->view_score);
    }

    function is_round_visible($rrow) {
        if (!$this->round_mask)
            return true;
        // NB missing $rrow is only possible for PC reviews
        $round = $rrow ? $rrow->reviewRound : $this->conf->assignment_round(false);
        return $round === null
            || ($this->round_mask & (1 << $round))
            || ($rrow
                && ($fid = $this->id)
                && isset($rrow->$fid)
                && ($this->has_options ? (int) $rrow->$fid !== 0 : $rrow->$fid !== ""));
    }

    function include_word_count() {
        return $this->displayed && !$this->has_options
            && $this->view_score >= VIEWSCORE_AUTHORDEC;
    }

    function typical_score() {
        if ($this->_typical_score === false && $this->has_options) {
            $n = count($this->options);
            if ($n == 1)
                $this->_typical_score = $this->unparse_value(1);
            else if ($this->option_letter)
                $this->_typical_score = $this->unparse_value($n - 1);
            else
                $this->_typical_score = $this->unparse_value(2);
        }
        return $this->_typical_score;
    }

    function typical_score_range() {
        if (!$this->has_options || count($this->options) < 2)
            return null;
        $n = count($this->options);
        if ($this->option_letter)
            return [$this->unparse_value($n - ($n > 2)), $this->unparse_value($n - 1 - ($n > 2) - ($n > 3))];
        else
            return [$this->unparse_value(1 + ($n > 2)), $this->unparse_value(2 + ($n > 2) + ($n > 3))];
    }

    function full_score_range() {
        if (!$this->has_options)
            return null;
        $f = $this->option_letter ? count($this->options) : 1;
        $l = $this->option_letter ? 1 : count($this->options);
        return [$this->unparse_value($f), $this->unparse_value($l)];
    }

    function abbreviations_for($name, $data) {
        assert($this === $data);
        return $this->search_keyword();
    }
    function search_keyword() {
        if ($this->abbreviation === null) {
            $am = $this->conf->abbrev_matcher();
            $aclass = new AbbreviationClass;
            $aclass->stopwords = $this->conf->review_form()->stopwords();
            $this->abbreviation = $am->unique_abbreviation($this->name, $this, $aclass);
            if (!$this->abbreviation)
                $this->abbreviation = $this->name;
        }
        return $this->abbreviation;
    }
    function abbreviation1() {
        $aclass = new AbbreviationClass;
        $aclass->type = AbbreviationClass::TYPE_LOWERDASH;
        return AbbreviationMatcher::make_abbreviation($this->name, $aclass);
    }
    function web_abbreviation() {
        return '<span class="need-tooltip" data-tooltip="' . $this->name_html
            . '" data-tooltip-dir="b">' . htmlspecialchars($this->search_keyword()) . "</span>";
    }
    function uid() {
        return $this->search_keyword();
    }

    static function unparse_letter($option_letter, $value) {
        $ivalue = (int) $value;
        $ch = $option_letter - $ivalue;
        if ($value < $ivalue + 0.25)
            return chr($ch);
        else if ($value < $ivalue + 0.75)
            return chr($ch - 1) . chr($ch);
        else
            return chr($ch - 1);
    }

    function value_class($value) {
        if (count($this->options) > 1)
            $n = (int) (($value - 1) * 8.0 / (count($this->options) - 1) + 1.5);
        else
            $n = 1;
        return "sv " . $this->option_class_prefix . $n;
    }

    function unparse_value($value, $flags = 0, $real_format = null) {
        if (is_object($value))
            $value = get($value, $this->id);
        if (!$this->has_options)
            return $value;
        if (!$value)
            return null;
        if (!$this->option_letter || is_numeric($value))
            $value = (float) $value;
        else if (strlen($value) === 1)
            $value = (float) $this->option_letter - ord($value);
        else if (ord($value[0]) + 1 === ord($value[1]))
            $value = ($this->option_letter - ord($value[0])) - 0.5;
        if (!is_float($value) || $value <= 0.8)
            return null;
        if ($this->option_letter)
            $text = self::unparse_letter($this->option_letter, $value);
        else if ($real_format)
            $text = sprintf($real_format, $value);
        else if ($flags & self::VALUE_STRING)
            $text = (string) $value;
        else
            $text = $value;
        if ($flags & (self::VALUE_SC | self::VALUE_REV_NUM)) {
            $vc = $this->value_class($value);
            if ($flags & self::VALUE_REV_NUM)
                $text = '<span class="rev_num ' . $vc . '">' . $text . '.</span>';
            else
                $text = '<span class="' . $vc . '">' . $text . '</span>';
        }
        return $text;
    }

    function value_description($value) {
        if (is_object($value))
            $value = get($value, $this->id);
        if (!$this->has_options)
            return null;
        else if (!$value)
            return "";
        else if ($this->option_letter && (is_int($value) || ctype_digit($value)))
            $value = chr($this->option_letter - (int) $value);
        return $this->options[$value];
    }

    function unparse_average($value) {
        assert($this->has_options);
        return (string) $this->unparse_value($value, 0, "%.2f");
    }

    function unparse_graph($v, $style, $myscore) {
        assert($this->has_options);
        $max = count($this->options);

        if (!is_object($v))
            $v = new ScoreInfo($v, true);
        $counts = $v->counts($max);

        $avgtext = $this->unparse_average($v->mean());
        if ($v->count() > 1 && ($stddev = $v->stddev_s()))
            $avgtext .= sprintf(" ± %.2f", $stddev);

        $args = "v=" . join(",", $counts);
        if ($myscore && $counts[$myscore - 1] > 0)
            $args .= "&amp;h=$myscore";
        if ($this->option_letter)
            $args .= "&amp;c=" . chr($this->option_letter - 1);
        if ($this->option_class_prefix !== "sv")
            $args .= "&amp;sv=" . urlencode($this->option_class_prefix);

        if ($style == 1) {
            $width = 5 * $max + 3;
            $height = 5 * max(3, max($counts)) + 3;
            $retstr = "<div class=\"need-scorechart\" style=\"width:${width}px;height:${height}px\" data-scorechart=\"$args&amp;s=1\" title=\"$avgtext\"></div>";
        } else if ($style == 2) {
            $retstr = "<div class=\"sc\">"
                . "<div class=\"need-scorechart\" style=\"width:64px;height:8px\" data-scorechart=\"$args&amp;s=2\" title=\"$avgtext\"></div>"
                . "<br />";
            if ($this->option_letter) {
                for ($key = $max; $key >= 1; --$key)
                    $retstr .= ($key < $max ? " " : "") . '<span class="' . $this->value_class($key) . '">' . $counts[$key - 1] . "</span>";
            } else {
                for ($key = 1; $key <= $max; ++$key)
                    $retstr .= ($key > 1 ? " " : "") . '<span class="' . $this->value_class($key) . '">' . $counts[$key - 1] . "</span>";
            }
            $retstr .= '<br /><span class="sc_sum">' . $avgtext . "</span></div>";
        }
        Ht::stash_script("$(scorechart)", "scorechart");

        return $retstr;
    }

    function parse_is_empty($text) {
        return $text === "" || $text === "0" || $text[0] === "("
            || strcasecmp($text, "No entry") == 0;
    }

    function parse_value($text, $strict) {
        if (!$strict && strlen($text) > 1
            && preg_match('/\A\s*([0-9]+|[A-Z])(?:\W|\z)/', $text, $m))
            $text = $m[1];
        if (!$strict && ctype_digit($text))
            $text = intval($text);
        if (!$text || !$this->has_options || !isset($this->options[$text]))
            return null;
        else if ($this->option_letter)
            return $this->option_letter - ord($text);
        else
            return (int) $text;
    }
}

class ReviewForm implements JsonSerializable {
    const NOTIFICATION_DELAY = 10800;

    public $conf;
    public $fmap = array();
    public $forder;
    public $fieldName;
    private $_stopwords;

    static public $revtype_names = [
        "None", "External", "PC", "Secondary", "Primary", "Meta"
    ];

    // XXX all negative ratings should have negative numbers
    // values are HTML
    static public $rating_types = array("n" => "average",
                                        1 => "very helpful",
                                        0 => "too short",
                                        -1 => "too vague",
                                        -4 => "too narrow",
                                        -2 => "not constructive",
                                        -3 => "not correct");
    static private $review_author_seen = null;

    static function fmap_compare($a, $b) {
        if ($a->displayed != $b->displayed)
            return $a->displayed ? -1 : 1;
        else if ($a->displayed && $a->display_order != $b->display_order)
            return $a->display_order < $b->display_order ? -1 : 1;
        else
            return strcmp($a->id, $b->id);
    }

    function __construct($rfj, Conf $conf) {
        $this->conf = $conf;

        // parse JSON
        if (!$rfj)
            $rfj = json_decode('{
"overAllMerit":{"name":"Overall merit","position":1,"visibility":"au",
  "options":["Reject","Weak reject","Weak accept","Accept","Strong accept"]},
"reviewerQualification":{"name":"Reviewer expertise","position":2,"visibility":"au",
  "options":["No familiarity","Some familiarity","Knowledgeable","Expert"]},
"t01":{"name":"Paper summary","position":3,"display_space":5,"visibility":"au"},
"t02":{"name":"Comments to authors","position":4,"visibility":"au"},
"t03":{"name":"Comments to PC","position":5,"visibility":"pc"}}');

        foreach ($rfj as $fid => $j)
            if (($finfo = ReviewInfo::field_info($fid, $conf))) {
                $f = new ReviewField($finfo, $conf);
                $this->fmap[$f->id] = $f;
                $f->assign($j);
            }

        // assign field order
        uasort($this->fmap, "ReviewForm::fmap_compare");
        $this->fieldName = $this->forder = [];
        $do = 0;
        foreach ($this->fmap as $f)
            if ($f->displayed) {
                $this->fieldName[strtolower($f->name)] = $f->id;
                $f->display_order = ++$do;
                $this->forder[$f->id] = $f;
            }
    }

    function all_fields() {
        return $this->forder;
    }

    function stopwords() {
        // Produce a list of common words in review field names that should be
        // avoided in abbreviations.
        // For instance, if three review fields start with "Double-blind
        // question:", we want to avoid those words.
        if ($this->_stopwords === null) {
            $bits = [];
            $bit = 1;
            foreach ($this->fmap as $f) {
                if (!$f->displayed)
                    continue;
                $words = preg_split('/[^A-Za-z0-9_.\']+/', strtolower(UnicodeHelper::deaccent($f->name)));
                if (count($words) <= 4) // Few words --> all of them meaningful
                    continue;
                foreach ($words as $w)
                    $bits[$w] = get($bits, $w, 0) | $bit;
                $bit <<= 1;
            }
            $stops = [];
            foreach ($bits as $w => $v)
                if ($v & ($v - 1))
                    $stops[] = str_replace("'", "", $w);
            $this->_stopwords = join("|", $stops);
        }
        return $this->_stopwords;
    }

    function field($fid) {
        $f = get($this->fmap, $fid);
        return $f && $f->displayed ? $f : null;
    }

    function default_display() {
        $f = $this->fmap["overAllMerit"];
        if (!$f->displayed) {
            foreach ($this->forder as $f)
                break;
        }
        return $f && $f->displayed ? " " . $f->search_keyword() . " " : " ";
    }

    function jsonSerialize() {
        $fmap = [];
        foreach ($this->fmap as $f)
            $fmap[$f->id] = $f->unparse_json(true);
        return $fmap;
    }
    function unparse_json($round_mask, $view_score_bound) {
        $fmap = array();
        foreach ($this->fmap as $f)
            if ($f->displayed
                && (!$round_mask || !$f->round_mask
                    || ($f->round_mask & $round_mask))
                && $f->view_score > $view_score_bound) {
                $fmap[$f->uid()] = $f->unparse_json();
            }
        return $fmap;
    }

    function unparse_ratings_json() {
        $rt = self::$rating_types;
        $rt["order"] = array_keys(self::$rating_types);
        return $rt;
    }

    private function format_info($rrow) {
        $format = $rrow ? $rrow->reviewFormat : null;
        if ($format === null)
            $format = $this->conf->default_format;
        return $format ? $this->conf->format_info($format) : null;
    }

    private function webFormRows($contact, $prow, $rrow, ReviewValues $rvalues = null) {
        $format_description = "";
        if (($fi = $this->format_info($rrow)))
            $format_description = $fi->description_preview_html();
        $revViewScore = $contact->view_score_bound($prow, $rrow);
        echo '<div class="rve">';
        foreach ($this->forder as $fid => $f) {
            if ($f->view_score <= $revViewScore
                || ($f->round_mask && !$f->is_round_visible($rrow)))
                continue;

            $fval = "";
            if ($rvalues && isset($rvalues->req[$fid]))
                $fval = $rvalues->req[$fid];
            else if ($rrow)
                $fval = $f->unparse_value(get($rrow, $fid), ReviewField::VALUE_STRING);

            echo '<div class="rv rveg" data-rf="', $f->uid(), '"><div class="revet';
            if ($rvalues && $rvalues->has_problem_at($fid))
                echo " error";
            echo '"><div class="revfn">', $f->name_html;
            if ($f->view_score < VIEWSCORE_REVIEWERONLY)
                echo '<div class="revvis">(secret)</div>';
            else if ($f->view_score < VIEWSCORE_PC)
                echo '<div class="revvis">(shown only to chairs)</div>';
            else if ($f->view_score < VIEWSCORE_AUTHOR)
                echo '<div class="revvis">(hidden from authors)</div>';
            echo '</div></div>';

            if ($f->description)
                echo '<div class="revhint">', $f->description, "</div>";

            echo '<div class="revev">';
            if ($f->has_options) {
                echo "<table><tbody>\n";
                // Keys to $f->options are string if option_letter, else int.
                // Need to match exactly.
                if (!$f->parse_value($fval, true))
                    $fval = 0;
                else if (!$f->option_letter)
                    $fval = (int) $fval;
                foreach ($f->options as $num => $what) {
                    echo '<tr><td class="nw">',
                        Ht::radio($fid, $num, $fval === $num, ["id" => $fid . "_" . $num]),
                        '&nbsp;</td>',
                        '<td class="nw">', Ht::label($f->unparse_value($num, ReviewField::VALUE_REV_NUM) . '&nbsp;'), '</td>',
                        '<td>', Ht::label(htmlspecialchars($what)), "</td></tr>\n";
                }
                if ($f->allow_empty)
                    echo '<tr><td class="nw">',
                        Ht::radio($fid, 0, $fval === 0, ["id" => $fid . "_0"]),
                        '&nbsp;</td>',
                        '<td colspan="2">', Ht::label("No entry"), "</td></tr>\n";
                echo "</tbody></table>";
            } else {
                echo $format_description;
                echo Ht::textarea($fid, (string) $fval,
                        array("class" => "reviewtext need-autogrow", "rows" => $f->display_space,
                              "cols" => 60, "onchange" => "hiliter(this)",
                              "spellcheck" => "true"));
            }
            echo "</div></div>\n";
        }
        echo "</div>\n";
    }

    function author_nonempty($rrow) {
        foreach ($this->forder as $fid => $f)
            if (isset($rrow->$fid)
                && (!$f->round_mask || $f->is_round_visible($rrow))
                && $f->view_score >= VIEWSCORE_AUTHORDEC
                && ($f->has_options ? (int) $rrow->$fid !== 0 : $rrow->$fid !== ""))
                return true;
        return false;
    }

    function word_count($rrow) {
        $wc = 0;
        foreach ($this->forder as $fid => $f)
            if (isset($rrow->$fid)
                && (!$f->round_mask || $f->is_round_visible($rrow))
                && $f->include_word_count()
                && $rrow->$fid !== "")
                $wc += count_words($rrow->$fid);
        return $wc;
    }

    function review_needs_approval($rrow) {
        return $rrow && !$rrow->reviewSubmitted
            && $rrow->reviewType == REVIEW_EXTERNAL
            && $rrow->requestedBy
            && $this->conf->setting("extrev_approve")
            && $this->conf->setting("pcrev_editdelegate");
    }


    static function update_review_author_seen() {
        while (self::$review_author_seen) {
            $conf = self::$review_author_seen[0][0];
            $q = $qv = $next = [];
            foreach (self::$review_author_seen as $x)
                if ($x[0] === $conf) {
                    $q[] = $x[1];
                    $qv[] = $x[2];
                    $qv[] = $x[3];
                } else
                    $next[] = $x;
            self::$review_author_seen = $next;
            $mresult = Dbl::multi_qe_apply($conf->dblink, join(";", $q), $qv);
            $mresult->free_all();
        }
    }

    static private function check_review_author_seen($prow, $rrow, $contact,
                                                     $no_update = false) {
        global $Now;
        if ($rrow && !$rrow->reviewAuthorSeen
            && $contact->act_author_view($prow)
            && !$contact->is_actas_user()) {
            // XXX combination of review tokens & authorship gets weird
            assert($rrow->reviewAuthorModified > 0);
            $rrow->reviewAuthorSeen = $Now;
            if (!$no_update) {
                if (!self::$review_author_seen) {
                    register_shutdown_function("ReviewForm::update_review_author_seen");
                    self::$review_author_seen = [];
                }
                self::$review_author_seen[] = [$contact->conf,
                    "update PaperReview set reviewAuthorSeen=? where reviewId=?",
                    $rrow->reviewAuthorSeen, $rrow->reviewId];
            }
        }
    }

    private static function rrow_modified_time($prow, $rrow, $contact, $revViewScore) {
        if (!$prow || !$rrow || !$contact->can_view_review_time($prow, $rrow))
            return 0;
        else if ($revViewScore >= VIEWSCORE_AUTHORDEC - 1) {
            if ($rrow->reviewAuthorModified !== null)
                return $rrow->reviewAuthorModified;
            else if (!$rrow->reviewAuthorNotified
                     || $rrow->reviewModified - $rrow->reviewAuthorNotified <= self::NOTIFICATION_DELAY)
                return $rrow->reviewModified;
            else
                return $rrow->reviewAuthorNotified;
        } else
            return $rrow->reviewModified;
    }

    function textFormHeader($type) {
        $x = "==+== " . $this->conf->short_name . " Paper Review Form" . ($type === true ? "s" : "") . "\n";
        $x .= "==-== DO NOT CHANGE LINES THAT START WITH \"==+==\" UNLESS DIRECTED!
==-== For further guidance, or to upload this file when you are done, go to:
==-== " . hoturl_absolute_raw("offline") . "\n\n";
        return $x;
    }

    function textForm($prow, $rrow, $contact, $req = null) {
        $rrow_contactId = $rrow ? $rrow->contactId : 0;
        $myReview = !$rrow || $rrow_contactId == 0 || $rrow_contactId == $contact->contactId;
        $revViewScore = $prow ? $contact->view_score_bound($prow, $rrow) : $contact->permissive_view_score_bound();
        self::check_review_author_seen($prow, $rrow, $contact);
        $viewable_identity = !$prow || $contact->can_view_review_identity($prow, $rrow, true);

        $x = "==+== =====================================================================\n";
        //$x .= "$prow->paperId:$myReview:$revViewScore:$rrow->contactId;;$prow->conflictType;;$prow->reviewType\n";

        $x .= "==+== Begin Review";
        if ($req && isset($req['reviewOrdinal']))
            $x .= " #" . $prow->paperId . unparseReviewOrdinal($req['reviewOrdinal']);
        else if ($rrow && isset($rrow->reviewOrdinal))
            $x .= " #" . $prow->paperId . unparseReviewOrdinal($rrow->reviewOrdinal);
        $x .= "\n";
        if ($rrow && defval($rrow, "reviewEditVersion") && $viewable_identity)
            $x .= "==+== Version " . $rrow->reviewEditVersion . "\n";
        if (!$prow || $viewable_identity) {
            if ($rrow && isset($rrow->reviewEmail))
                $x .= "==+== Reviewer: " . Text::user_text($rrow->reviewFirstName, $rrow->reviewLastName, $rrow->reviewEmail) . "\n";
            else if ($rrow && isset($rrow->email))
                $x .= "==+== Reviewer: " . Text::user_text($rrow) . "\n";
            else
                $x .= "==+== Reviewer: " . Text::user_text($contact) . "\n";
        }
        $time = self::rrow_modified_time($prow, $rrow, $contact, $revViewScore);
        if ($time > 1)
            $x .= "==-== Updated " . $this->conf->printableTime($time) . "\n";

        if ($prow)
            $x .= "\n==+== Paper #$prow->paperId\n"
                . prefix_word_wrap("==-== Title: ", $prow->title, "==-==        ")
                . "\n";
        else
            $x .= "\n==+== Paper Number\n\n(Enter paper number here)\n\n";

        if ($viewable_identity) {
            $x .= "==+== Review Readiness
==-== Enter \"Ready\" if the review is ready for others to see:

Ready\n";
            if ($this->conf->review_blindness() == Conf::BLIND_OPTIONAL) {
                $blind = "Anonymous";
                if ($rrow && !$rrow->reviewBlind)
                    $blind = "Open";
                $x .= "\n==+== Review Anonymity
==-== " . $this->conf->short_name . " allows either anonymous or open review.
==-== Enter \"Open\" if you want to expose your name to authors:

$blind\n";
            }
        }

        $i = 0;
        $numericMessage = 0;
        $format_description = "";
        if (($fi = $this->format_info($rrow)))
            $format_description = $fi->description_text();
        foreach ($this->forder as $fid => $f) {
            $i++;
            if ($f->view_score <= $revViewScore
                || ($f->round_mask && !$f->is_round_visible($rrow)))
                continue;

            $fval = "";
            if ($req && isset($req[$fid]))
                $fval = rtrim($req[$fid]);
            else if ($rrow != null && isset($rrow->$fid)) {
                if ($f->has_options)
                    $fval = $f->unparse_value($rrow->$fid, ReviewField::VALUE_STRING);
                else
                    $fval = rtrim(str_replace("\r\n", "\n", $rrow->$fid));
            }
            if ($f->has_options && isset($f->options[$fval]))
                $fval = "$fval. " . $f->options[$fval];
            else if (!$fval)
                $fval = "";

            $y = "==+== " . chr(64 + $i) . ". ";
            $x .= "\n" . prefix_word_wrap($y, $f->name, "==+==    ");
            if ($f->description) {
                $d = cleannl($f->description);
                if (strpbrk($d, "&<") !== false)
                    $d = Text::html_to_text($d);
                $x .= prefix_word_wrap("==-==    ", trim($d), "==-==    ");
            }
            if ($f->has_options) {
                $x .= "==-== Choices:\n";
                foreach ($f->options as $num => $value) {
                    $y = "==-==    $num. ";
                    $x .= prefix_word_wrap($y, $value, str_pad("==-==", strlen($y)));
                }
                if ($f->allow_empty)
                    $x .= "==-==    No entry\n";
            }
            if ($f->view_score < VIEWSCORE_REVIEWERONLY)
                $x .= "==-== Secret field.\n";
            else if ($f->view_score < VIEWSCORE_PC)
                $x .= "==-== Shown only to chairs.\n";
            else if ($f->view_score < VIEWSCORE_AUTHOR)
                $x .= "==-== Hidden from authors.\n";
            if ($f->has_options) {
                if ($f->allow_empty)
                    $x .= "==-== Enter your choice:\n";
                else if ($f->option_letter)
                    $x .= "==-== Enter the letter of your choice:\n";
                else
                    $x .= "==-== Enter the number of your choice:\n";
                if ($fval == "" && $f->allow_empty)
                    $fval = "No entry";
                else if ($fval == "")
                    $fval = "(Your choice here)";
            } else if ($format_description !== "")
                $x .= prefix_word_wrap("==-== ", $format_description, "==-== ");
            $x .= "\n" . preg_replace("/^==\\+==/m", "\\==+==", $fval) . "\n";
        }
        return $x . "\n==+== Scratchpad (for unsaved private notes)\n\n==+== End Review\n";
    }

    function pretty_text(PaperInfo $prow, $rrow, Contact $contact,
                         $no_update_review_author_seen = false,
                         $no_title = false) {
        assert($prow !== null && $rrow !== null);

        $revViewScore = $contact->view_score_bound($prow, $rrow);
        self::check_review_author_seen($prow, $rrow, $contact, $no_update_review_author_seen);

        $n = ($no_title ? "" : $this->conf->short_name . " ") . "Review";
        if (get($rrow, "reviewOrdinal"))
            $n .= " #" . $prow->paperId . unparseReviewOrdinal($rrow->reviewOrdinal);
        $x = $n . "\n" . str_repeat("=", 75) . "\n";

        if (!$no_title)
            $x .= prefix_word_wrap("* ", "Paper: #{$prow->paperId} {$prow->title}", 2);
        if ($contact->can_view_review_identity($prow, $rrow, false) && isset($rrow->lastName))
            $x .= "* Reviewer: " . Text::user_text($rrow) . "\n";
        $time = self::rrow_modified_time($prow, $rrow, $contact, $revViewScore);
        if ($time > 1)
            $x .= "* Updated: " . $this->conf->printableTime($time) . "\n";

        foreach ($this->forder as $fid => $f) {
            if ($f->view_score <= $revViewScore
                || ($f->round_mask && !$f->is_round_visible($rrow)))
                continue;

            $fval = "";
            if (isset($rrow->$fid)) {
                if ($f->has_options)
                    $fval = $f->unparse_value($rrow->$fid, ReviewField::VALUE_STRING);
                else
                    $fval = rtrim(str_replace("\r\n", "\n", $rrow->$fid));
            }
            if ($fval == "")
                continue;

            $x .= "\n";
            $x .= $f->name . "\n" . str_repeat("-", strlen($f->name)) . "\n";

            if ($f->has_options) {
                $y = get($f->options, $fval, "");
                $x .= prefix_word_wrap($fval . ". ", $y, strlen($fval) + 2);
            } else
                $x .= preg_replace("/^==\\+==/m", "\\==+==", $fval) . "\n";
        }
        return $x;
    }

    function webGuidanceRows($revViewScore, $extraclass="") {
        $x = '';

        foreach ($this->forder as $fid => $f) {
            if ($f->view_score <= $revViewScore
                || (!$f->description && !$f->has_options))
                continue;

            $x .= "<tr class='rev_$fid'>\n";
            $x .= "  <td class='caption rev_$fid$extraclass'>";
            $x .= $f->name_html . "</td>\n";

            $x .= "  <td class='entry rev_$fid$extraclass'>";
            if ($f->description)
                $x .= "<div class='rev_description'>" . $f->description . "</div>";
            if ($f->has_options) {
                $x .= "<div class='rev_options'>Choices are:";
                foreach ($f->options as $num => $val)
                    $x .= "<br />\n" . $f->unparse_value($num, ReviewField::VALUE_REV_NUM) . " " . htmlspecialchars($val);
                $x .= "</div>";
            }

            $x .= "</td>\n</tr>\n";
            $extraclass = "";
        }

        return $x;
    }

    private function _echo_accept_decline($prow, $rrow, $reviewPostLink) {
        if ($rrow && !$rrow->reviewModified && $rrow->reviewType < REVIEW_SECONDARY) {
            $buttons = [];
            $buttons[] = Ht::submit("accept", "Accept", ["class" => "btn btn-highlight"]);
            $buttons[] = Ht::button("Decline", ["onclick" => "popup(this,'ref',0)"]);
            // Also see $_REQUEST["refuse"] case in review.php.
            Ht::stash_html("<div id='popup_ref' class='popupc'>"
    . Ht::form_div($reviewPostLink)
    . Ht::hidden("refuse", "refuse")
    . "<p style='margin:0 0 0.3em'>Select “Decline review” to decline this review. Thank you for keeping us informed.</p>"
    . Ht::textarea("reason", null,
                   array("id" => "refusereviewreason", "rows" => 3, "cols" => 40,
                         "placeholder" => "Optional explanation", "spellcheck" => "true"))
    . '<div class="popup-actions">'
    . Ht::submit("Decline review", ["class" => "btn"])
    . Ht::js_button("Cancel", "popup(null,'ref',1)", ["class" => "btn"])
    . "</div></div></form></div>", "declinereviewform");
            if ($rrow->requestedBy && ($requester = $this->conf->cached_user_by_id($rrow->requestedBy)))
                $req = 'Please take a moment to accept or decline ' . Text::name_html($requester) . '’s review request.';
            else
                $req = 'Please take a moment to accept or decline our review request.';
            echo '<div class="revcard_bodyinsert">',
                Ht::actions($buttons, ["class" => "aab aabr aabig", "style" => "margin-top:0"],
                            '<div style="padding-top:5px">' . $req . '</div>'),
                "</div>\n";
        }
    }

    private function _echo_review_actions($prow, $rrow, $type, $reviewPostLink) {
        global $Me;
        $buttons = array();

        $submitted = $rrow && $rrow->reviewSubmitted;
        $disabled = !$Me->can_clickthrough("review");
        $submit_text = "Submit review";
        if ($this->review_needs_approval($rrow)) {
            if ($Me->contactId == $rrow->contactId) /* XXX */
                $submit_text = "Submit for approval";
            else if ($rrow->timeApprovalRequested)
                $submit_text = "Approve review";
        }
        if (!$this->conf->time_review($rrow, $Me->act_pc($prow, true), true)) {
            $whyNot = array("deadline" => ($rrow && $rrow->reviewType < REVIEW_PC ? "extrev_hard" : "pcrev_hard"));
            $override_text = whyNotText($whyNot, "review");
            if (!$submitted) {
                $buttons[] = array(Ht::js_button("Submit review", "override_deadlines(this)", ["class" => "btn btn-default", "data-override-text" => $override_text, "data-override-submit" => "submitreview"]), "(admin only)");
                $buttons[] = array(Ht::js_button("Save as draft", "override_deadlines(this)", ["class" => "btn", "data-override-text" => $override_text, "data-override-submit" => "savedraft"]), "(admin only)");
            } else
                $buttons[] = array(Ht::js_button("Save changes", "override_deadlines(this)", ["class" => "btn btn-default", "data-override-text" => $override_text, "data-override-submit" => "submitreview"]), "(admin only)");
        } else if (!$submitted) {
            // NB see `PaperTable::_echo_clickthrough` data-clickthrough-enable
            $buttons[] = Ht::submit("submitreview", $submit_text, ["class" => "btn btn-default", "disabled" => $disabled]);
            $buttons[] = Ht::submit("savedraft", "Save as draft", ["class" => "btn", "disabled" => $disabled]);
        } else
            // NB see `PaperTable::_echo_clickthrough` data-clickthrough-enable
            $buttons[] = Ht::submit("submitreview", "Save changes", ["class" => "btn btn-default", "disabled" => $disabled]);

        if ($rrow && $type == "bottom" && $Me->allow_administer($prow)) {
            $buttons[] = "";
            if ($submitted)
                $buttons[] = array(Ht::submit("unsubmitreview", "Unsubmit review", ["class" => "btn"]), "(admin only)");
            $buttons[] = array(Ht::js_button("Delete review", "popup(this,'d',0)", ["class" => "btn"]), "(admin only)");
            Ht::stash_html("<div id='popup_d' class='popupc'>
  <p>Be careful: This will permanently delete all information about this
  review assignment from the database and <strong>cannot be
  undone</strong>.</p>
  " . Ht::form_div($reviewPostLink, array("divclass" => "popup-actions"))
    . Ht::submit("deletereview", "Delete review", ["class" => "btn dangerous"])
    . Ht::js_button("Cancel", "popup(null,'d',1)", ["class" => "btn"])
    . "</div></form></div>");
        }

        echo Ht::actions($buttons, ["class" => "aab aabr aabig", "style" => "margin-$type:0"]);
    }

    function show(PaperInfo $prow, $rrow, &$options, ReviewValues $rvalues = null) {
        global $Me;

        if (!$options)
            $options = array();
        $editmode = get($options, "edit", false);

        $reviewOrdinal = unparseReviewOrdinal($rrow);
        self::check_review_author_seen($prow, $rrow, $Me);

        if (!$editmode) {
            $rj = $this->unparse_review_json($prow, $rrow, $Me);
            if (get($options, "editmessage"))
                $rj->message_html = $options["editmessage"];
            echo Ht::unstash_script("review_form.add_review(" . json_encode_browser($rj) . ");\n");
            return;
        }

        // From here on, edit mode.
        $forceShow = $Me->is_admin_force() ? "&amp;forceShow=1" : "";
        $reviewLinkArgs = "p=$prow->paperId" . ($rrow ? "&amp;r=$reviewOrdinal" : "") . "&amp;m=re" . $forceShow;
        $reviewPostLink = hoturl_post("review", $reviewLinkArgs);
        $reviewDownloadLink = hoturl("review", $reviewLinkArgs . "&amp;downloadForm=1" . $forceShow);

        echo Ht::form($reviewPostLink, array("class" => "editrevform")),
            '<div class="aahc">',
            Ht::hidden_default_submit("default", "");
        if ($rrow)
            echo Ht::hidden("version", defval($rrow, "reviewEditVersion", 0) + 1);
        echo '<div class="revcard" id="r', $reviewOrdinal, '"><div class="revcard_head">';

        // Links
        if ($rrow) {
            echo '<div class="floatright"><a href="' . hoturl("review", "r=$reviewOrdinal&amp;text=1" . $forceShow) . '" class="xx">',
                Ht::img("txt.png", "[Text]", "b"),
                "&nbsp;<u>Plain text</u></a>",
                "</div>";
        }

        echo "<h3>";
        if ($rrow) {
            echo '<a href="', hoturl("review", "r=$reviewOrdinal" . $forceShow), '" class="q">Edit Review';
            if ($rrow->reviewOrdinal)
                echo "&nbsp;#", $reviewOrdinal;
            echo "</a>";
        } else
            echo "Write Review";
        echo "</h3>\n";

        $open = $sep = " <span class='revinfo'>";
        $xsep = " <span class='barsep'>·</span> ";
        $showtoken = $rrow && $Me->review_token_cid($prow, $rrow);
        $type = "";
        if ($rrow && $Me->can_view_review_round($prow, $rrow, null)) {
            $type = review_type_icon($rrow->reviewType);
            if ($rrow->reviewRound > 0 && $Me->can_view_review_round($prow, $rrow, null))
                $type .= "&nbsp;<span class=\"revround\" title=\"Review round\">"
                    . htmlspecialchars($this->conf->round_name($rrow->reviewRound))
                    . "</span>";
        }
        if ($rrow && $Me->can_view_review_identity($prow, $rrow, null)
            && (!$showtoken || !Contact::is_anonymous_email($rrow->email))) {
            echo $sep, ($rrow->reviewBlind ? "[" : ""), Text::user_html($rrow),
                ($rrow->reviewBlind ? "]" : ""), " &nbsp;", $type;
            $sep = $xsep;
        } else if ($type) {
            echo $sep, $type;
            $sep = $xsep;
        }
        if ($showtoken) {
            echo $sep, "Review token ", encode_token((int) $rrow->reviewToken);
            $sep = $xsep;
        }
        if ($rrow && $rrow->reviewModified > 1 && $Me->can_view_review_time($prow, $rrow)) {
            echo $sep, "Updated ", $this->conf->printableTime($rrow->reviewModified);
            $sep = $xsep;
        }
        if ($sep != $open)
            echo "</span>\n";

        if (defval($options, "editmessage"))
            echo '<div class="hint">', defval($options, "editmessage"), "</div>\n";

        // download?
        echo '<hr class="c" />';
        echo "<table class='revoff'><tr>
      <td><strong>Offline reviewing</strong> &nbsp;</td>
      <td>Upload form: &nbsp; <input type='file' name='uploadedFile' accept='text/plain' size='30' />
      &nbsp; ", Ht::submit("uploadForm", "Go"), "</td>
    </tr><tr>
      <td></td>
      <td><a href='$reviewDownloadLink'>Download form</a>
      <span class='barsep'>·</span>
      <span class='hint'><strong>Tip:</strong> Use <a href='", hoturl("search"), "'>Search</a> or <a href='", hoturl("offline"), "'>Offline reviewing</a> to download or upload many forms at once.</span></td>
    </tr></table></div>\n";

        // review card
        echo '<div class="revcard_body">';

        // administrator?
        $admin = $Me->allow_administer($prow);
        if ($rrow && !$Me->is_my_review($rrow)) {
            if ($Me->is_owned_review($rrow))
                echo Ht::xmsg("info", "This isn’t your review, but you can make changes since you requested it.");
            else if ($admin)
                echo Ht::xmsg("info", "This isn’t your review, but as an administrator you can still make changes.");
        }

        // delegate?
        if ($rrow && !$rrow->reviewSubmitted
            && $rrow->contactId == $Me->contactId
            && $rrow->reviewType == REVIEW_SECONDARY) {
            $ndelegated = 0;
            foreach ($prow->reviews_by_id() as $rr)
                if ($rr->reviewType == REVIEW_EXTERNAL
                    && $rr->requestedBy == $rrow->contactId)
                    $ndelegated++;

            if ($ndelegated == 0)
                $t = "As a secondary reviewer, you can <a href=\"" . hoturl("assign", "p=$rrow->paperId") . "\">delegate this review to an external reviewer</a>, but if your external reviewer declines to review the paper, you should complete this review yourself.";
            else if ($rrow->reviewNeedsSubmit == 0)
                $t = "A delegated external reviewer has submitted their review, but you can still complete your own if you’d like.";
            else
                $t = "Your delegated external reviewer has not yet submitted a review.  If they do not, you should complete this review yourself.";
            echo Ht::xmsg("info", $t);
        }

        // top save changes
        if ($Me->timeReview($prow, $rrow) || $admin) {
            $this->_echo_accept_decline($prow, $rrow, $reviewPostLink);
            $this->_echo_review_actions($prow, $rrow, "top", $reviewPostLink);
        }

        // blind?
        if ($this->conf->review_blindness() == Conf::BLIND_OPTIONAL) {
            echo '<div class="revet"><span class="revfn">',
                Ht::hidden("has_blind", 1),
                Ht::checkbox("blind", 1, ($rvalues ? !!get($rvalues->req, "blind") : (!$rrow || $rrow->reviewBlind))),
                "&nbsp;", Ht::label("Anonymous review"),
                "</span><hr class=\"c\" /></div>\n",
                '<div class="revhint">', htmlspecialchars($this->conf->short_name), " allows either anonymous or open review.  Check this box to submit your review anonymously (the authors won’t know who wrote the review).</div>\n",
                '<div class="g"></div>', "\n";
        }

        // form body
        $this->webFormRows($Me, $prow, $rrow, $rvalues);

        // review actions
        if ($Me->timeReview($prow, $rrow) || $admin) {
            $this->_echo_review_actions($prow, $rrow, "bottom", $reviewPostLink);
            if ($rrow && $rrow->reviewSubmitted && !$admin)
                echo '<div class="hint">Only administrators can remove or unsubmit the review at this point.</div>';
        }

        echo "</div></div></div></form>\n\n";
        Ht::stash_script('hiliter_children(".editrevform")', "form_revcard");
    }

    const RJ_NO_EDITABLE = 2;
    const RJ_UNPARSE_RATINGS = 4;
    const RJ_ALL_RATINGS = 8;
    const RJ_NO_REVIEWERONLY = 16;

    static private function unparse_rating($rating, $flags) {
        $rating = (int) $rating;
        if (($flags & self::RJ_UNPARSE_RATINGS) && isset(self::$rating_types[$rating]))
            return self::$rating_types[$rating];
        else
            return $rating;
    }

    function unparse_review_json(PaperInfo $prow, $rrow, Contact $contact,
                                 $forceShow = null, $flags = 0) {
        self::check_review_author_seen($prow, $rrow, $contact);
        $revViewScore = $contact->view_score_bound($prow, $rrow);
        $editable = !($flags & self::RJ_NO_EDITABLE);

        $rj = array("pid" => $prow->paperId, "rid" => (int) $rrow->reviewId);
        if ($rrow->reviewOrdinal)
            $rj["ordinal"] = unparseReviewOrdinal($rrow->reviewOrdinal);
        if ($contact->can_view_review_round($prow, $rrow, $forceShow)) {
            $rj["rtype"] = (int) $rrow->reviewType;
            if (($round = $this->conf->round_name($rrow->reviewRound)))
                $rj["round"] = $round;
        }
        if ($rrow->reviewBlind)
            $rj["blind"] = true;
        if ($rrow->reviewSubmitted)
            $rj["submitted"] = true;
        else if (!$rrow->reviewOrdinal)
            $rj["draft"] = true;
        if (!$rrow->reviewSubmitted && $rrow->timeApprovalRequested)
            $rj["needs_approval"] = true;
        if ($contact->can_review($prow, $rrow) && $editable)
            $rj["editable"] = true;

        // identity and time
        $showtoken = $contact->review_token_cid($prow, $rrow) && $editable;
        if ($contact->can_view_review_identity($prow, $rrow, $forceShow)
            && (!$showtoken || !Contact::is_anonymous_email($rrow->email))) {
            $rj["reviewer"] = Text::user_html($rrow);
            $rj["reviewer_name"] = Text::name_text($rrow);
            $rj["reviewer_email"] = $rrow->email;
        }
        if ($showtoken)
            $rj["review_token"] = encode_token((int) $rrow->reviewToken);
        $time = self::rrow_modified_time($prow, $rrow, $contact, $revViewScore);
        if ($time > 1) {
            $rj["modified_at"] = (int) $time;
            $rj["modified_at_text"] = $this->conf->printableTime($time);
        }

        // ratings
        if ((string) $rrow->allRatings !== ""
            && $contact->can_view_review_ratings($prow, $rrow, ($flags & self::RJ_ALL_RATINGS) != 0)) {
            $ratings = [];
            foreach (explode(",", $rrow->allRatings) as $rx) {
                list($cid, $rating) = explode(" ", $rx);
                $ratings[] = self::unparse_rating($rating, $flags);
            }
            $rj["ratings"] = $ratings;
        }
        if ($editable && $contact->can_rate_review($prow, $rrow)) {
            if ((string) $rrow->allRatings !== ""
                && preg_match('/(?:\A|,)' . $contact->contactId . ' (\d+)/', $rrow->allRatings, $m))
                $rj["user_rating"] = self::unparse_rating($m[1], $flags);
            else
                $rj["user_rating"] = null;
        }

        // review text
        // (field UIDs always are uppercase so can't conflict)
        foreach ($this->forder as $fid => $f)
            if ($f->view_score > $revViewScore
                && (!$f->round_mask || $f->is_round_visible($rrow))
                && ($f->view_score > VIEWSCORE_REVIEWERONLY
                    || !($flags & self::RJ_NO_REVIEWERONLY))) {
                $fval = get($rrow, $fid);
                if ($f->has_options)
                    $fval = $f->unparse_value((int) $fval);
                $rj[$f->uid()] = $fval;
            }
        if (($fmt = $rrow->reviewFormat) === null)
            $fmt = $this->conf->default_format;
        if ($fmt)
            $rj["format"] = $fmt;

        return (object) $rj;
    }


    function unparse_flow_entry(PaperInfo $prow, ReviewInfo $rrow, Contact $contact) {
        // See also CommentInfo::unparse_flow_entry
        $barsep = ' <span class="barsep">·</span> ';
        $a = '<a href="' . hoturl("paper", "p=$prow->paperId#r" . unparseReviewOrdinal($rrow)) . '"';
        $t = '<tr class="pl"><td class="pl_activityicon">' . $a . '>'
            . Ht::img("review48.png", "[Review]", ["class" => "dlimg", "width" => 24, "height" => 24])
            . '</a></td><td class="pl_activityid pl_rowclick">'
            . $a . ' class="pnum">#' . $prow->paperId . '</a></td>'
            . '<td class="pl_activitymain pl_rowclick"><small>'
            . $a . ' class="ptitle">'
            . htmlspecialchars(UnicodeHelper::utf8_abbreviate($prow->title, 80))
            . "</a>";
        if ($rrow->reviewModified > 1) {
            if ($contact->can_view_review_time($prow, $rrow))
                $time = $this->conf->parseableTime($rrow->reviewModified, false);
            else
                $time = $this->conf->unparse_time_obscure($this->conf->obscure_time($rrow->reviewModified));
            $t .= $barsep . $time;
        }
        if ($contact->can_view_review_identity($prow, $rrow, false))
            $t .= $barsep . "<span class='hint'>review by</span> " . $contact->reviewer_html_for($rrow);
        $t .= "</small><br />";

        $revViewScore = $contact->view_score_bound($prow, $rrow);
        if ($rrow->reviewSubmitted) {
            $t .= "Review #" . unparseReviewOrdinal($rrow) . " submitted";
            $xbarsep = $barsep;
        } else
            $xbarsep = "";
        foreach ($this->forder as $fid => $f)
            if (isset($rrow->$fid)
                && $f->view_score > $revViewScore
                && $f->has_options
                && (int) $rrow->$fid !== 0) {
                $t .= $xbarsep . $f->name_html . "&nbsp;"
                    . $f->unparse_value((int) $rrow->$fid, ReviewField::VALUE_SC);
                $xbarsep = $barsep;
            }

        return $t . "</td></tr>";
    }
}

class ReviewValues extends MessageSet {
    public $rf;
    public $conf;

    public $text;
    public $filename;
    public $lineno;
    public $firstLineno;
    public $fieldLineno;
    private $garbage_lineno;

    public $paperId;
    public $req;

    private $finished = 0;
    public $newlySubmitted;
    public $updated;
    public $approvalRequested;
    public $savedDraft;
    public $authorNotified;
    public $unchanged;
    public $ignoredBlank;

    private $_mailer_template;
    private $_mailer_always_combine;
    private $_mailer_diff_view_score;
    private $_mailer_info;
    private $_mailer_preps;

    function __construct(ReviewForm $rf) {
        $this->rf = $rf;
        $this->conf = $rf->conf;
    }

    static function make_text(ReviewForm $rf, $text, $filename = null) {
        $rv = new ReviewValues($rf);
        $rv->text = $text;
        $rv->lineno = 0;
        $rv->filename = $filename;
        return $rv;
    }

    function rmsg($field, $msg, $status) {
        $e = "";
        if ($this->filename) {
            $e .= htmlspecialchars($this->filename);
            if (is_int($field)) {
                if ($field)
                    $e .= ":" . $field;
                $field = null;
            } else if ($field && isset($this->fieldLineno[$field]))
                $e .= ":" . $this->fieldLineno[$field];
            else
                $e .= ":" . $this->lineno;
            if ($this->paperId)
                $e .= " (paper #" . $this->paperId . ")";
        }
        if ($e)
            $msg = '<span class="lineno">' . $e . ':</span> ' . $msg;
        $this->msg($field, $msg, $status);
    }

    private function check_garbage() {
        if ($this->garbage_lineno)
            $this->rmsg($this->garbage_lineno, "Review form appears to begin with garbage; ignoring it.", self::WARNING);
        $this->garbage_lineno = null;
    }

    function parse_text($override) {
        assert($this->text !== null && $this->finished === 0);

        $text = $this->text;
        $this->firstLineno = $this->lineno + 1;
        $this->fieldLineno = [];
        $this->garbage_lineno = null;
        $this->req = [];
        $this->paperId = false;
        if ($override !== null)
            $this->req["override"] = $override;

        $mode = 0;
        $nfields = 0;
        $field = 0;
        $anyDirectives = 0;

        while ($text !== "") {
            $pos = strpos($text, "\n");
            $line = ($pos === false ? $text : substr($text, 0, $pos + 1));
            ++$this->lineno;

            if (substr($line, 0, 6) == "==+== ") {
                // make sure we record that we saw the last field
                if ($mode && $field != null && !isset($this->req[$field]))
                    $this->req[$field] = "";

                $anyDirectives++;
                if (preg_match('{\A==\+==\s+(.*?)\s+(Paper Review(?: Form)?s?)\s*\z}', $line, $m)
                    && $m[1] != $this->conf->short_name) {
                    $this->check_garbage();
                    $this->rmsg("confid", "Ignoring review form, which appears to be for a different conference.<br />(If this message is in error, replace the line that reads “<code>" . htmlspecialchars(rtrim($line)) . "</code>” with “<code>==+== " . htmlspecialchars($this->conf->short_name) . " " . $m[2] . "</code>” and upload again.)", self::ERROR);
                    return false;
                } else if (preg_match('/^==\+== Begin Review/i', $line)) {
                    if ($nfields > 0)
                        break;
                } else if (preg_match('/^==\+== Paper #?(\d+)/i', $line, $match)) {
                    if ($nfields > 0)
                        break;
                    $this->paperId = intval($match[1]);
                    $this->req["blind"] = 1;
                    $this->firstLineno = $this->fieldLineno["paperNumber"] = $this->lineno;
                } else if (preg_match('/^==\+== Reviewer:\s*(.*)$/', $line, $match)
                           && ($user = Text::split_name($match[1], true))
                           && $user[2]) {
                    $this->fieldLineno["reviewerEmail"] = $this->lineno;
                    $this->req["reviewerFirst"] = $user[0];
                    $this->req["reviewerLast"] = $user[1];
                    $this->req["reviewerEmail"] = $user[2];
                } else if (preg_match('/^==\+== Paper (Number|\#)\s*$/i', $line)) {
                    if ($nfields > 0)
                        break;
                    $field = "paperNumber";
                    $this->fieldLineno[$field] = $this->lineno;
                    $mode = 1;
                    $this->req["blind"] = 1;
                    $this->firstLineno = $this->lineno;
                } else if (preg_match('/^==\+== Submit Review\s*$/i', $line)
                           || preg_match('/^==\+== Review Ready\s*$/i', $line)) {
                    $this->req["ready"] = true;
                } else if (preg_match('/^==\+== Open Review\s*$/i', $line)) {
                    $this->req["blind"] = 0;
                } else if (preg_match('/^==\+== Version\s*(\d+)$/i', $line, $match)) {
                    if (get($this->req, "version", 0) < intval($match[1]))
                        $this->req["version"] = intval($match[1]);
                } else if (preg_match('/^==\+== Review Readiness\s*/i', $line)) {
                    $field = "readiness";
                    $mode = 1;
                } else if (preg_match('/^==\+== Review Anonymity\s*/i', $line)) {
                    $field = "anonymity";
                    $mode = 1;
                } else if (preg_match('/^==\+== Review Format\s*/i', $line)) {
                    $field = "reviewFormat";
                    $mode = 1;
                } else if (preg_match('/^==\+== [A-Z]\.\s*(.*?)\s*$/', $line, $match)) {
                    while (substr($text, strlen($line), 6) === "==+== ") {
                        $pos = strpos($text, "\n", strlen($line));
                        $xline = ($pos === false ? substr($text, strlen($line)) : substr($text, strlen($line), $pos + 1 - strlen($line)));
                        if (preg_match('/^==\+==\s+(.*?)\s*$/', $xline, $xmatch))
                            $match[1] .= " " . $xmatch[1];
                        $line .= $xline;
                    }
                    $field = get($this->rf->fieldName, strtolower($match[1]));
                    if (!$field) {
                        $fname = preg_replace('/\s*\((hidden from authors|PC only|shown only to chairs|secret)\)\z/i', "", $match[1]);
                        $field = get($this->rf->fieldName, strtolower($fname));
                    }
                    if ($field) {
                        $this->fieldLineno[$field] = $this->lineno;
                        $nfields++;
                    } else {
                        $this->check_garbage();
                        $this->rmsg(null, "Review field “" . htmlentities($match[1]) . "” is not used for " . htmlspecialchars($this->conf->short_name) . " reviews.  Ignoring this section.", self::ERROR);
                    }
                    $mode = 1;
                } else {
                    $field = null;
                    $mode = 1;
                }
            } else if ($mode < 2 && (substr($line, 0, 5) == "==-==" || ltrim($line) == ""))
                /* ignore line */;
            else {
                if ($mode == 0) {
                    $this->garbage_lineno = $this->lineno;
                    $field = null;
                }
                if ($field != null)
                    $this->req[$field] = get($this->req, $field, "") . $line;
                $mode = 2;
            }

            $text = (string) substr($text, strlen($line));
        }

        if ($nfields == 0 && $this->firstLineno == 1)
            $this->rmsg(null, "That didn’t appear to be a review form; I was not able to extract any information from it.  Please check its formatting and try again.", self::ERROR);

        $this->text = $text;
        --$this->lineno;

        if (isset($this->req["readiness"]))
            $this->req["ready"] = strcasecmp(trim($this->req["readiness"]), "Ready") == 0;
        if (isset($this->req["anonymity"]))
            $this->req["blind"] = strcasecmp(trim($this->req["anonymity"]), "Open") != 0;
        if (isset($this->req["reviewFormat"]))
            $this->req["reviewFormat"] = trim($this->req["reviewFormat"]);

        if ($this->paperId)
            /* OK */;
        else if (isset($this->req["paperNumber"])
                 && ($pid = cvtint(trim($this->req["paperNumber"]), -1)) > 0)
            $this->paperId = $pid;
        else if ($nfields > 0) {
            $this->rmsg("paperNumber", "This review form doesn’t report which paper number it is for.  Make sure you’ve entered the paper number in the right place and try again.", self::ERROR);
            $nfields = 0;
        }

        if ($nfields == 0 && $text) // try again
            return $this->parse_text($override);
        else
            return $nfields != 0;
    }

    function parse_json($j) {
        assert($this->text === null && $this->finished === 0);

        if (!is_object($j) && !is_array($j))
            return false;
        $this->req = [];

        // XXX validate more
        $first = $last = null;
        foreach ($j as $k => $v) {
            if ($k === "round") {
                if ($v === null || is_string($v))
                    $this->req["round"] = $v;
            } else if ($k === "blind") {
                if (is_bool($v))
                    $this->req["blind"] = $v ? 1 : 0;
            } else if ($k === "submitted") {
                if (is_bool($v))
                    $this->req["ready"] = $v ? 1 : 0;
            } else if ($k === "draft") {
                if (is_bool($v))
                    $this->req["ready"] = $v ? 0 : 1;
            } else if ($k === "name" || $k === "reviewer_name") {
                if (is_string($v))
                    list($this->req["reviewerFirst"], $this->req["reviewerLast"]) = Text::split_name($v);
            } else if ($k === "email" || $k === "reviewer_email") {
                if (is_string($v))
                    $this->req["reviewerEmail"] = trim($v);
            } else if ($k === "affiliation" || $k === "reviewer_affiliation") {
                if (is_string($v))
                    $this->req["reviewerAffiliation"] = $v;
            } else if ($k === "first" || $k === "firstName") {
                if (is_string($v))
                    $this->req["reviewerFirst"] = simplify_whitespace($v);
            } else if ($k === "last" || $k === "lastName") {
                if (is_string($v))
                    $this->req["reviewerLast"] = simplify_whitespace($v);
            } else if ($k === "format") {
                if (is_int($v))
                    $this->req["reviewFormat"] = $v;
            } else if ($k === "version") {
                if (is_int($v))
                    $this->req["version"] = $v;
            } else if (($f = $this->conf->find_review_field($k))) {
                if ((is_string($v) || is_int($v) || $v === null)
                    && !isset($this->req[$f->id]))
                    $this->req[$f->id] = $v;
            }
        }
        if (!empty($this->req) && isset($this->req["ready"]))
            $this->req["ready"] = 1;

        return !empty($this->req);
    }

    static private $ignore_web_keys = [
        "submitreview" => true, "savedraft" => true, "unsubmitreview" => true,
        "deletereview" => true, "r" => true, "m" => true, "post" => true,
        "forceShow" => true, "update" => true, "has_blind" => true, "default" => true
    ];

    function parse_web(Qrequest $qreq, $override) {
        assert($this->text === null && $this->finished === 0);
        $this->req = [];
        foreach ($qreq as $k => $v) {
            if (isset(self::$ignore_web_keys[$k]) || !is_scalar($v))
                /* skip */;
            else if ($k === "p")
                $this->paperId = cvtint($v);
            else if ($k === "forceShow")
                $this->req["override"] = !!$v;
            else if ($k === "blind" || $k === "version" || $k === "ready")
                $this->req[$k] = is_bool($v) ? (int) $v : cvtint($v);
            else if ($k === "format")
                $this->req["reviewFormat"] = cvtint($v);
            else if (isset($this->rf->fmap[$k]))
                $this->req[$k] = $v;
            else if (($f = $this->conf->find_review_field($k))
                     && !isset($this->req[$f->id]))
                $this->req[$f->id] = $v;
        }
        if (!empty($this->req)) {
            if (!$qreq->has_blind && !isset($this->req["blind"]))
                $this->req["blind"] = 1;
            if ($override)
                $this->req["override"] = 1;
            return true;
        } else
            return false;
    }

    private function reviewer_error($msg) {
        if (!$msg)
            $msg = $this->conf->_("Can’t submit a review for %s.", htmlspecialchars($this->req["reviewerEmail"]));
        $this->rmsg("reviewerEmail", $msg, self::ERROR);
        $this->rmsg("reviewerEmail", $this->conf->_("(You may be mistakenly submitting a review form intended for someone else.)"), self::INFO);
    }

    function check_and_save(Contact $user, PaperInfo $prow = null, ReviewInfo $rrow = null) {
        assert(!$rrow || $rrow->paperId == $prow->paperId);

        // look up paper
        if (!$prow) {
            if (!$this->paperId) {
                $this->rmsg("paperNumber", "This review form doesn’t report which paper number it is for.  Make sure you’ve entered the paper number in the right place and try again.", self::ERROR);
                return false;
            }
            $prow = $this->conf->paperRow($this->paperId, $user, $whyNot);
            if (!$prow) {
                $this->rmsg("paperNumber", whyNotText($whyNot, "review"), self::ERROR);
                return false;
            }
        }
        if ($this->paperId && $prow->paperId != $this->paperId) {
            $this->rmsg("paperNumber", "This review form is for paper #{$this->paperId}, not paper #{$prow->paperId}; did you mean to upload it here? I have ignored the form.", MessageSet::ERROR);
            return false;
        }
        $this->paperId = $prow->paperId;

        // look up reviewer
        $reviewer = $user;
        if ($rrow) {
            if ($rrow->contactId != $user->contactId)
                $reviewer = $this->conf->cached_user_by_id($rrow->contactId);
        } else if (isset($this->req["reviewerEmail"])
                   && strcasecmp($this->req["reviewerEmail"], $user->email) != 0) {
            if (!($reviewer = $this->conf->user_by_email($this->req["reviewerEmail"]))) {
                $this->reviewer_error($user->privChair ? $this->conf->_("No such user %s.", htmlspecialchars($this->req["reviewerEmail"])) : null);
                return false;
            }
        }

        // look up review
        if (!$rrow)
            $rrow = $prow->fresh_review_of_user($reviewer);
        if (!$rrow && $user->review_tokens()) {
            $prow->ensure_full_reviews();
            foreach ($prow->reviews_by_id() as $xrrow)
                if ($xrrow->reviewToken
                    && in_array($xrrow->reviewToken, $user->review_tokens())) {
                    $rrow = $xrrow;
                    break;
                }
        }

        // maybe create review
        $new_rrid = false;
        if (!$rrow && $user !== $reviewer) {
            if (!$user->can_create_review_from($prow, $reviewer)) {
                $this->reviewer_error(null);
                return false;
            }
            $extra = [];
            if (isset($this->req["round"]))
                $extra["round_number"] = $this->conf->round_number($this->req["round"], false);
            $new_rrid = $user->assign_review($prow->paperId, $reviewer->contactId, $reviewer->isPC ? REVIEW_PC : REVIEW_EXTERNAL, $extra);
            if (!$new_rrid) {
                $this->rmsg(null, "Internal error while creating review.", self::ERROR);
                return false;
            }
            $rrow = $prow->fresh_review_of_id($new_rrid);
        }

        // check permission
        $whyNot = $user->perm_submit_review($prow, $rrow);
        if ($whyNot) {
            if ($user === $reviewer || $user->can_view_review_identity($prow, $rrow))
                $this->rmsg(null, whyNotText($whyNot, "review"), self::ERROR);
            else
                $this->reviewer_error(null);
            return false;
        }

        // actually check review and save
        if ($this->check($rrow))
            return $this->do_save($user, $prow, $rrow);
        else {
            if ($new_rrid)
                $user->assign_review($prow->paperId, $reviewer->contactId, 0);
            return false;
        }
    }

    private function check(ReviewInfo $rrow = null) {
        $submit = get($this->req, "ready");
        $before_nerrors = $this->nerrors();
        $nokfields = 0;
        $unready = false;
        foreach ($this->rf->forder as $fid => $f) {
            if (isset($this->req[$fid]))
                $fval = $this->req[$fid];
            else if ($submit
                     && (!$f->round_mask || $f->is_round_visible($rrow))) {
                if ($rrow && isset($rrow->$fid))
                    $fval = $f->unparse_value($rrow->$fid, ReviewField::VALUE_STRING);
                else
                    $fval = "";
            } else
                continue;
            if ($f->has_options) {
                $fval = trim($fval);
                if ($f->parse_is_empty($fval)) {
                    if ($f->allow_empty)
                        ++$nokfields;
                    else if ($submit && $f->view_score >= VIEWSCORE_PC) {
                        $this->warning_at($fid, $this->conf->_("You must provide a value for %s in order to submit your review.", $f->name_html));
                        $unready = true;
                    }
                } else if ($f->parse_value($fval, false))
                    ++$nokfields;
                else {
                    $this->warning_at($fid, $this->conf->_("Bad %s value “%s”.", $f->name_html, htmlspecialchars(UnicodeHelper::utf8_abbreviate($fval, 100))));
                    unset($this->req[$fid]);
                    $unready = true;
                }
            } else if (trim($fval) !== "")
                ++$nokfields;
        }
        if ($rrow
            && isset($this->req["reviewerEmail"])
            && strcasecmp($rrow->email, $this->req["reviewerEmail"]) != 0
            && (!isset($this->req["reviewerFirst"])
                || !isset($this->req["reviewerLast"])
                || strcasecmp($this->req["reviewerFirst"], $rrow->firstName) != 0
                || strcasecmp($this->req["reviewerLast"], $rrow->lastName) != 0)) {
            $msg = $this->conf->_("The review form was meant for %s, but this review belongs to %s. If you want to upload the form anyway, remove the “<code class=\"nw\">==+== Reviewer</code>” line from the form.", Text::user_html(["firstName" => get($this->req, "reviewerFirst"), "lastName" => get($this->req, "reviewerLast"), "email" => $this->req["reviewerEmail"]]), Text::user_html($rrow));
            $this->error_at("reviewerEmail", $msg);
        } else if ($rrow
                   && $rrow->reviewEditVersion > get($this->req, "version", 0)
                   && $nokfields > 0
                   && $this->text !== null) {
            $this->rmsg($this->firstLineno, "This review has been edited online since you downloaded this offline form, so for safety I am not replacing the online version.  If you want to override your online edits, add a line “<code>==+==&nbsp;Version&nbsp;" . $rrow->reviewEditVersion . "</code>” to your offline review form for paper #{$this->paperId} and upload the form again.", self::ERROR);
        } else if ($unready) {
            $this->warning_at("ready", null);
            $this->req["ready"] = 0;
        }
        if ($this->nerrors() !== $before_nerrors)
            return false;
        else if ($nokfields > 0)
            return true;
        else {
            $this->ignoredBlank[] = "#" . $this->paperId;
            return false;
        }
    }

    function review_watch_callback($prow, $minic) {
        $rrow = $this->_mailer_info["rrow"];
        if ($minic->can_view_review($prow, $rrow, false, $this->_mailer_diff_view_score)
            && ($p = HotCRPMailer::prepare_to($minic, $this->_mailer_template, $prow, $this->_mailer_info))) {
            // Don't combine preparations unless you can see all submitted
            // reviewer identities
            if (!$this->_mailer_always_combine
                && !$minic->can_view_review_identity($prow, $rrow, false))
                $p->unique_preparation = true;
            $this->_mailer_preps[] = $p;
        }
    }

    private function do_save(Contact $user, PaperInfo $prow, $rrow) {
        assert($this->paperId == $prow->paperId);
        assert(!$rrow || $rrow->paperId == $prow->paperId);

        $newsubmit = $approval_requested = false;
        if (get($this->req, "ready")
            && (!$rrow || !$rrow->reviewSubmitted)) {
            if ($user->isPC || !$this->rf->review_needs_approval($rrow))
                $newsubmit = true;
            else
                $approval_requested = true;
        }
        $submit = $newsubmit || ($rrow && $rrow->reviewSubmitted);
        $admin = $user->allow_administer($prow);

        if (!$user->timeReview($prow, $rrow)
            && (!isset($this->req["override"]) || !$admin)) {
            $this->rmsg(null, "The <a href='" . hoturl("deadlines") . "'>deadline</a> for entering this review has passed." . ($admin ? " Select the “Override deadlines” checkbox and try again if you really want to override the deadline." : ""), self::ERROR);
            return false;
        }

        $qf = $qv = [];
        $tfields = $sfields = $set_sfields = $set_tfields = null;
        $diff_fields = [];
        $diff_view_score = VIEWSCORE_FALSE;
        $wc = 0;
        foreach ($this->rf->forder as $fid => $f)
            if (isset($this->req[$fid])
                && (!$f->round_mask || $f->is_round_visible($rrow))) {
                $fval = $this->req[$fid];
                if ($f->has_options) {
                    $fval = trim($fval);
                    if ($f->parse_is_empty($fval))
                        $fval = 0;
                    else if (!($fval = $f->parse_value($fval, false)))
                        continue;
                    $old_fval = $rrow ? (int) get($rrow, $fid, 0) : 0;
                    if ($fval === 0 && $rrow && !$f->allow_empty)
                        $fval = $old_fval;
                    $fval_diffs = $fval !== $old_fval;
                } else {
                    $fval = rtrim($fval);
                    if ($fval !== "")
                        $fval .= "\n";
                    // Check for valid UTF-8; re-encode from Windows-1252 or Mac OS
                    $fval = convert_to_utf8($fval);
                    if ($f->include_word_count())
                        $wc += count_words($fval);
                    $old_fval = $rrow ? get($rrow, $fid, "") : "";
                    $fval_diffs = $fval !== $old_fval
                        && cleannl($fval) !== cleannl($old_fval);
                }
                if ($fval_diffs) {
                    $diff_view_score = max($diff_view_score, $f->view_score);
                    if ($rrow)
                        $diff_fields[] = $f->search_keyword();
                }
                if ($fval_diffs || !$rrow) {
                    if ($f->main_storage) {
                        $qf[] = "{$f->main_storage}=?";
                        $qv[] = $fval;
                    }
                    if ($f->json_storage) {
                        if ($f->has_options) {
                            if ($fval != 0)
                                $sfields[$f->json_storage] = $fval;
                            $set_sfields[$fid] = true;
                        } else {
                            if ($fval !== "")
                                $tfields[$f->json_storage] = $fval;
                            $set_tfields[$fid] = true;
                        }
                    }
                }
            }
        // complete `sfields` and `tfields` with existing fields
        if ($rrow) {
            foreach ($this->rf->forder as $fid => $f)
                if ($f->json_storage) {
                    if ($f->has_options && $set_sfields && !isset($set_sfields[$fid])) {
                        $fval = (int) get($rrow, $fid, 0);
                        if ($fval !== 0)
                            $sfields[$f->json_storage] = $fval;
                    } else if (!$f->has_options && $set_tfields && !isset($set_tfields[$fid])) {
                        $fval = get($rrow, $fid, "");
                        if ($fval !== "") {
                            $tfields[$f->json_storage] = $fval;
                            if ($f->include_word_count())
                                $wc += count_words($fval);
                        }
                    }
                }
        }
        if ($set_sfields !== null) {
            $qf[] = "sfields=?";
            $qv[] = $sfields ? json_encode_db($sfields) : null;
        }
        if ($set_tfields !== null) {
            $qf[] = "tfields=?";
            $qv[] = $tfields ? json_encode_db($tfields) : null;
        }

        // get the current time
        $now = time();
        if ($rrow && $rrow->reviewModified > 1 && $rrow->reviewModified > $now)
            $now = $rrow->reviewModified + 1;

        if ($newsubmit) {
            array_push($qf, "reviewSubmitted=?", "reviewNeedsSubmit=?");
            array_push($qv, $now, 0);
        }
        if ($approval_requested) {
            $qf[] = "timeApprovalRequested=?";
            $qv[] = $now;
        }

        // potentially assign review ordinal (requires table locking since
        // mySQL is stupid)
        $locked = $newordinal = false;
        if ((!$rrow && $newsubmit && $diff_view_score >= VIEWSCORE_AUTHORDEC)
            || ($rrow && !$rrow->reviewOrdinal
                && ($rrow->reviewSubmitted > 0 || $newsubmit)
                && ($diff_view_score >= VIEWSCORE_AUTHORDEC
                    || $this->rf->author_nonempty($rrow)))) {
            $table_suffix = "";
            if ($this->conf->au_seerev == Conf::AUSEEREV_TAGS)
                $table_suffix = ", PaperTag read";
            $result = $this->conf->qe_raw("lock tables PaperReview write" . $table_suffix);
            if (!$result)
                return $result;
            $locked = true;
            $max_ordinal = $this->conf->fetch_ivalue("select coalesce(max(reviewOrdinal), 0) from PaperReview where paperId=? group by paperId", $prow->paperId);
            if ($max_ordinal !== null) {
                // NB `coalesce(reviewOrdinal,0)` is not necessary in modern schemas
                $qf[] = "reviewOrdinal=if(coalesce(reviewOrdinal,0)=0,?,reviewOrdinal)";
                $qv[] = $max_ordinal + 1;
            }
            Dbl::free($result);
            $newordinal = true;
        }
        if ($newsubmit || $newordinal) {
            $qf[] = "timeDisplayed=?";
            $qv[] = $now;
        }

        // check whether used a review token
        $usedReviewToken = $user->review_token_cid($prow, $rrow);

        // blind? reviewer type? edit version?
        $reviewBlind = $this->conf->is_review_blind(!!get($this->req, "blind"));
        if (!$rrow
            || $reviewBlind != $rrow->reviewBlind) {
            $diff_view_score = max($diff_view_score, VIEWSCORE_ADMINONLY);
            $qf[] = "reviewBlind=?";
            $qv[] = $reviewBlind ? 1 : 0;
        }
        if ($rrow
            && $rrow->reviewType == REVIEW_EXTERNAL
            && $user->contactId == $rrow->contactId
            && $user->isPC
            && !$usedReviewToken) {
            $qf[] = "reviewType=?";
            $qv[] = REVIEW_PC;
        }
        if ($rrow
            && $diff_view_score > VIEWSCORE_FALSE
            && isset($this->req["version"])
            && ctype_digit($this->req["version"])
            && $this->req["version"] > get($rrow, "reviewEditVersion")) {
            $qf[] = "reviewEditVersion=?";
            $qv[] = $this->req["version"] + 0;
        }
        if ($diff_view_score > VIEWSCORE_FALSE
            && $this->conf->sversion >= 98) {
            $qf[] = "reviewWordCount=?";
            $qv[] = $wc;
        }
        if (isset($this->req["reviewFormat"])
            && $this->conf->sversion >= 104
            && $this->conf->opt("formatInfo")) {
            $fmt = null;
            foreach ($this->conf->opt("formatInfo") as $k => $f)
                if (get($f, "name") && strcasecmp($f["name"], $this->req["reviewFormat"]) == 0)
                    $fmt = (int) $k;
            if (!$fmt && $this->req["reviewFormat"]
                && preg_match('/\A(?:plain\s*)?(?:text)?\z/i', $f["reviewFormat"]))
                $fmt = 0;
            $qf[] = "reviewFormat=?";
            $qv[] = $fmt;
        }

        // notification
        $notification_bound = $now - ReviewForm::NOTIFICATION_DELAY;
        $notify = $notify_author = false;
        if ($diff_view_score == VIEWSCORE_AUTHORDEC && $prow->outcome != 0
            && $prow->can_author_view_decision())
            $diff_view_score = VIEWSCORE_AUTHOR;
        if (!$rrow || !$rrow->reviewModified || $diff_view_score > VIEWSCORE_FALSE) {
            $qf[] = "reviewModified=?";
            $qv[] = $now;
        }
        if (!$rrow || $diff_view_score > VIEWSCORE_FALSE) {
            if ($diff_view_score >= VIEWSCORE_AUTHOR) {
                $qf[] = "reviewAuthorModified=?";
                $qv[] = $now;
            } else if ($rrow && !$rrow->reviewAuthorModified
                       && $rrow->reviewModified) {
                $qf[] = "reviewAuthorModified=?";
                $qv[] = $rrow->reviewModified;
            }
            // do not notify on updates within 3 hours
            if ($submit && $diff_view_score > VIEWSCORE_ADMINONLY) {
                if (!$rrow || !$rrow->reviewNotified
                    || $rrow->reviewNotified < $notification_bound) {
                    $qf[] = "reviewNotified=?";
                    $qv[] = $now;
                    $notify = true;
                }
                if ((!$rrow || !$rrow->reviewAuthorNotified
                     || $rrow->reviewAuthorNotified < $notification_bound)
                    && $diff_view_score >= VIEWSCORE_AUTHOR
                    && Contact::can_some_author_view_submitted_review($prow)) {
                    $qf[] = "reviewAuthorNotified=?";
                    $qv[] = $now;
                    $notify_author = true;
                }
            }
        }

        // actually affect database
        if ($rrow) {
            if (!empty($qf)) {
                array_push($qv, $prow->paperId, $rrow->reviewId);
                $result = $this->conf->qe_apply("update PaperReview set " . join(", ", $qf) . " where paperId=? and reviewId=?", $qv);
            } else
                $result = true;
            $reviewId = $rrow->reviewId;
            $contactId = $rrow->contactId;
        } else {
            array_unshift($qf, "paperId=?", "contactId=?", "reviewType=?", "requestedBy=?", "reviewRound=?");
            array_unshift($qv, $prow->paperId, $user->contactId, REVIEW_PC, $user->contactId, $this->conf->assignment_round(false));
            $result = $this->conf->qe_apply("insert into PaperReview set " . join(", ", $qf), $qv);
            $reviewId = $result ? $result->insert_id : null;
            $contactId = $user->contactId;
        }

        // unlock tables even if problem
        if ($locked)
            $this->conf->qe_raw("unlock tables");
        if (!$result)
            return false;

        // update caches
        Contact::update_rights();

        // look up review ID
        if (!$reviewId)
            return false;
        $this->req["reviewId"] = $reviewId;

        // log updates -- but not if review token is used
        if (!$usedReviewToken && $diff_view_score > VIEWSCORE_FALSE) {
            $text = "Review $reviewId "
                . ($newsubmit ? "submitted" : ($submit ? "updated" : "updated draft"))
                . ($diff_fields ? " " . join(", ", $diff_fields) : "");
            $user->log_activity_for($rrow ? $rrow->contactId : $user->contactId, $text, $prow);
        }

        // potentially email chair, reviewers, and authors
        $submitter = $user;
        if ($contactId != $submitter->contactId)
            $submitter = $this->conf->cached_user_by_id($contactId);
        if ($submit || $approval_requested || ($rrow && $rrow->timeApprovalRequested))
            $rrow = $prow->fresh_review_of_id($reviewId);

        $this->_mailer_preps = [];
        $this->_mailer_info = ["rrow" => $rrow, "reviewer_contact" => $submitter,
                               "check_function" => "HotCRPMailer::check_can_view_review"];
        if ($submit && $rrow->reviewOrdinal)
            $this->_mailer_info["reviewNumber"] = $prow->paperId . unparseReviewOrdinal($rrow->reviewOrdinal);
        if ($submit && ($notify || $notify_author) && $rrow) {
            $this->_mailer_template = $newsubmit ? "@reviewsubmit" : "@reviewupdate";
            $this->_mailer_always_combine = false;
            $this->_mailer_diff_view_score = $diff_view_score;
            if ($this->conf->timeEmailChairAboutReview())
                HotCRPMailer::send_manager($this->_mailer_template, $prow, $this->_mailer_info);
            $prow->notify(WATCHTYPE_REVIEW, array($this, "review_watch_callback"), $user);
        } else if ($rrow && !$submit && ($approval_requested || $rrow->timeApprovalRequested)) {
            $this->_mailer_template = $approval_requested ? "@reviewapprovalrequest" : "@reviewapprovalupdate";
            $this->_mailer_always_combine = true;
            $this->_mailer_diff_view_score = null;
            $this->_mailer_info["rrow_unsubmitted"] = true;
            if ($this->conf->timeEmailChairAboutReview())
                HotCRPMailer::send_manager($this->_mailer_template, $prow, $this->_mailer_info);
            if ($rrow->requestedBy && ($requester = $this->conf->cached_user_by_id($rrow->requestedBy))) {
                $this->review_watch_callback($prow, $requester);
                $this->review_watch_callback($prow, $submitter);
            }
        }
        if (!empty($this->_mailer_preps))
            HotCRPMailer::send_combined_preparations($this->_mailer_preps);
        unset($this->_mailer_info, $this->_mailer_preps);

        // if external, forgive the requestor from finishing their review
        if ($rrow && $rrow->reviewType < REVIEW_SECONDARY && $rrow->requestedBy && $submit)
            $this->conf->q_raw("update PaperReview set reviewNeedsSubmit=0 where paperId=$prow->paperId and contactId=$rrow->requestedBy and reviewType=" . REVIEW_SECONDARY . " and reviewSubmitted is null");

        $what = "#$prow->paperId" . ($rrow && $rrow->reviewSubmitted ? unparseReviewOrdinal($rrow->reviewOrdinal) : "");
        if ($newsubmit)
            $this->newlySubmitted[] = $what;
        else if ($diff_view_score > VIEWSCORE_FALSE && $submit)
            $this->updated[] = $what;
        else if ($approval_requested || ($rrow && $rrow->timeApprovalRequested))
            $this->approvalRequested[] = $what;
        else if ($diff_view_score > VIEWSCORE_FALSE)
            $this->savedDraft[] = $what;
        else
            $this->unchanged[] = $what;
        if ($notify_author)
            $this->authorNotified[] = $what;

        return true;
    }

    private function _confirm_message($fmt, $info) {
        $pids = array();
        foreach ($info as &$x)
            if (preg_match('/\A(#?)(\d+)([A-Z]*)\z/', $x, $m)) {
                $x = "<a href=\"" . hoturl("paper", ["p" => $m[2], "anchor" => $m[3] ? "r$m[2]$m[3]" : null]) . "\">" . $x . "</a>";
                $pids[] = $m[2];
            }
        $t = $this->conf->_($fmt, count($info), commajoin($info), $this->text === null);
        if (count($pids) > 1)
            $t = '<span class="has-hotlist" data-hotlist="p/s/' . join("+", $pids) . '">' . $t . '</span>';
        $this->msg(null, $t, self::INFO);
        return true;
    }

    function finish() {
        $confirm = false;
        if ($this->newlySubmitted)
            $confirm = $this->_confirm_message("Reviews %2\$s submitted.", $this->newlySubmitted);
        if ($this->updated)
            $confirm = $this->_confirm_message("Reviews %2\$s updated.", $this->updated);
        if ($this->approvalRequested)
            $confirm = $this->_confirm_message("Reviews %2\$s submitted for approval.", $this->newlySubmitted);
        if ($this->savedDraft)
            $confirm = $this->_confirm_message("Draft reviews for papers %2\$s saved.", $this->savedDraft);
        if ($this->authorNotified)
            $this->_confirm_message("Authors were notified about updated reviews %2\$s.", $this->authorNotified);
        if (count($this->unchanged) + count($this->ignoredBlank) > 1
            || $this->text !== null
            || !$this->has_messages()) {
            if ($this->unchanged)
                $this->_confirm_message("Reviews %2\$s unchanged.", $this->unchanged);
            if ($this->ignoredBlank)
                $this->_confirm_message("Ignored blank review forms %2\$s.", $this->ignoredBlank);
        }
        $this->finished = $confirm ? 2 : 1;
    }

    function report() {
        if (!$this->finished)
            $this->finish();
        if ($this->finished < 3 && $this->has_messages()) {
            $hdr = "";
            if ($this->text !== null) {
                if ($this->has_error() && $this->has_warning())
                    $hdr = $this->conf->_("There were errors and warnings while parsing the uploaded review file.");
                else if ($this->has_error())
                    $hdr = $this->conf->_("There were errors while parsing the uploaded review file.");
                else if ($this->has_warning())
                    $hdr = $this->conf->_("There were warnings while parsing the uploaded review file.");
            }
            $m = '<div class="parseerr"><p>' . join("</p>\n<p>", $this->messages()) . '</p></div>';
            $this->conf->msg($this->has_error() || $this->has_problem_at("ready") ? "merror" : ($this->has_warning() || $this->finished == 1 ? "warning" : "confirm"),
                $hdr . $m);
        }
        $this->finished = 3;
    }

    function json_report() {
        $j = [];
        foreach (["newlySubmitted" => "submitted",
            "updated" => "updated",
            "approvalRequested" => "approval_requested",
            "savedDraft" => "saved_draft",
            "authorNotified" => "author_notified",
            "unchanged" => "unchanged",
            "ignoredBlank" => "blank"] as $k => $jk)
            if ($this->$k)
                $j[$jk] = $this->$k;
        return $j;
    }
}
