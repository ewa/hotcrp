<?php
// test05.php -- HotCRP review and some setting tests
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/setup.php");
require_once("$ConfSitePATH/src/settingvalues.php");

// load users
$user_chair = $Conf->user_by_email("chair@_.com");
$user_mgbaker = $Conf->user_by_email("mgbaker@cs.stanford.edu"); // pc
$Conf->save_setting("rev_open", 1);

// 1-18 have 3 assignments, reset have 0
assert_search_papers($user_chair, "re:3", "1-18");
assert_search_papers($user_chair, "-re:3", "19-30");
assert_search_papers($user_chair, "ire:3", "1-18");
assert_search_papers($user_chair, "-ire:3", "19-30");
assert_search_papers($user_chair, "pre:3", "");
assert_search_papers($user_chair, "-pre:3", "1-30");
assert_search_papers($user_chair, "cre:3", "");
assert_search_papers($user_chair, "-cre:3", "1-30");

assert_search_papers($user_chair, "re:mgbaker", "1 13 17");
assert_search_papers($user_chair, "-re:mgbaker", "2-12 14-16 18-30");
assert_search_papers($user_chair, "ire:mgbaker", "1 13 17");
assert_search_papers($user_chair, "-ire:mgbaker", "2-12 14-16 18-30");
assert_search_papers($user_chair, "pre:mgbaker", "");
assert_search_papers($user_chair, "-pre:mgbaker", "1-30");
assert_search_papers($user_chair, "cre:mgbaker", "");
assert_search_papers($user_chair, "-cre:mgbaker", "1-30");

// Add a partial review
save_review(1, $user_mgbaker, ["overAllMerit" => 5, "ready" => false]);

assert_search_papers($user_chair, "re:3", "1-18");
assert_search_papers($user_chair, "-re:3", "19-30");
assert_search_papers($user_chair, "ire:3", "1-18");
assert_search_papers($user_chair, "-ire:3", "19-30");
assert_search_papers($user_chair, "pre:3", "");
assert_search_papers($user_chair, "-pre:3", "1-30");
assert_search_papers($user_chair, "cre:3", "");
assert_search_papers($user_chair, "-cre:3", "1-30");
assert_search_papers($user_chair, "pre:any", "1");
assert_search_papers($user_chair, "-pre:any", "2-30");
assert_search_papers($user_chair, "cre:any", "");
assert_search_papers($user_chair, "-cre:any", "1-30");

assert_search_papers($user_chair, "re:mgbaker", "1 13 17");
assert_search_papers($user_chair, "-re:mgbaker", "2-12 14-16 18-30");
assert_search_papers($user_chair, "ire:mgbaker", "1 13 17");
assert_search_papers($user_chair, "-ire:mgbaker", "2-12 14-16 18-30");
assert_search_papers($user_chair, "pre:mgbaker", "1");
assert_search_papers($user_chair, "-pre:mgbaker", "2-30");
assert_search_papers($user_chair, "cre:mgbaker", "");
assert_search_papers($user_chair, "-cre:mgbaker", "1-30");

assert_search_papers($user_chair, "ovemer:5", "");

// Add a complete review
save_review(1, $user_mgbaker, ["overAllMerit" => 5, "reviewerQualification" => 1, "ready" => true]);

assert_search_papers($user_chair, "re:3", "1-18");
assert_search_papers($user_chair, "-re:3", "19-30");
assert_search_papers($user_chair, "ire:3", "2-18");
assert_search_papers($user_chair, "-ire:3", "1 19-30");
assert_search_papers($user_chair, "pre:3", "");
assert_search_papers($user_chair, "-pre:3", "1-30");
assert_search_papers($user_chair, "cre:3", "");
assert_search_papers($user_chair, "-cre:3", "1-30");
assert_search_papers($user_chair, "pre:any", "");
assert_search_papers($user_chair, "-pre:any", "1-30");
assert_search_papers($user_chair, "cre:any", "1");
assert_search_papers($user_chair, "-cre:any", "2-30");

assert_search_papers($user_chair, "re:mgbaker", "1 13 17");
assert_search_papers($user_chair, "-re:mgbaker", "2-12 14-16 18-30");
assert_search_papers($user_chair, "ire:mgbaker", "13 17");
assert_search_papers($user_chair, "-ire:mgbaker", "1-12 14-16 18-30");
assert_search_papers($user_chair, "pre:mgbaker", "");
assert_search_papers($user_chair, "-pre:mgbaker", "1-30");
assert_search_papers($user_chair, "cre:mgbaker", "1");
assert_search_papers($user_chair, "-cre:mgbaker", "2-30");

assert_search_papers($user_chair, "ovemer:5", "1");


// Test offline review parsing

// Change a score
$paper1 = fetch_paper(1, $user_chair);
$rrow = fetch_review($paper1, $user_mgbaker);
$review1A = file_get_contents("$ConfSitePATH/test/review1A.txt");
$tf = ReviewValues::make_text($Conf->review_form(), $review1A, "review1A.txt");
xassert($tf->parse_text(false));
xassert($tf->check_and_save($user_mgbaker));

assert_search_papers($user_chair, "ovemer:4", "1");

// Catch different-conference form
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/Testconf I/', 'Testconf IIII', $review1A), "review1A-1.txt");
xassert(!$tf->parse_text(false));
xassert($tf->has_error_at("confid"));

// Catch invalid value
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/^4/m', 'Mumps', $review1A), "review1A-2.txt");
xassert($tf->parse_text(false));
xassert($tf->check_and_save($user_mgbaker));
xassert_eqq(join(" ", $tf->unchanged), "#1A");
xassert($tf->has_problem_at("overAllMerit"));

// “No entry” is invalid
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/^4/m', 'No entry', $review1A), "review1A-3.txt");
xassert($tf->parse_text(false));
xassert($tf->check_and_save($user_mgbaker));
xassert_eqq(join(" ", $tf->unchanged), "#1A");
xassert($tf->has_problem_at("overAllMerit"));
xassert(strpos(join("\n", $tf->messages_at("overAllMerit")), "must provide") !== false);
//error_log(var_export($tf->messages(true), true));

// Different reviewer
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/Reviewer: .*/m', 'Reviewer: butt@butt.com', $review1A), "review1A-4.txt");
xassert($tf->parse_text(false));
xassert(!$tf->check_and_save($user_mgbaker));
xassert($tf->has_problem_at("reviewerEmail"));

// Different reviewer
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/Reviewer: .*/m', 'Reviewer: Mary Baaaker <mgbaker193r8219@butt.com>', preg_replace('/^4/m', "5", $review1A)), "review1A-5.txt");
xassert($tf->parse_text(false));
xassert(!$tf->check_and_save($user_mgbaker, $paper1, fetch_review($paper1, $user_mgbaker)));
xassert($tf->has_problem_at("reviewerEmail"));

// Different reviewer with same name (OK)
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/Reviewer: .*/m', 'Reviewer: Mary Baker <mgbaker193r8219@butt.com>', preg_replace('/^4/m', "5", $review1A)), "review1A-5.txt");
xassert($tf->parse_text(false));
xassert($tf->check_and_save($user_mgbaker, $paper1, fetch_review($paper1, $user_mgbaker)));
xassert(!$tf->has_problem_at("reviewerEmail"));
//error_log(var_export($tf->messages(true), true));


// Settings changes

// Add “no entry”
$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_s01" => "Overall merit",
    "options_s01" => "1. Reject\n2. Weak reject\n3. Weak accept\n4. Accept\n5. Strong accept\nNo entry\n"
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

// Now it's OK to save “no entry”
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/^4/m', 'No entry', $review1A), "review1A-6.txt");
xassert($tf->parse_text(false));
xassert($tf->check_and_save($user_mgbaker));
xassert_eqq(join(" ", $tf->updated), "#1A");
xassert(!$tf->has_problem_at("overAllMerit"));
//error_log(var_export($tf->messages(true), true));

assert_search_papers($user_chair, "has:ovemer", "");

// Restore overall-merit 4
$tf = ReviewValues::make_text($Conf->review_form(), $review1A, "review1A-7.txt");
xassert($tf->parse_text(false));
xassert($tf->check_and_save($user_mgbaker));
xassert_eqq(join(" ", $tf->updated), "#1A");
xassert(!$tf->has_problem_at("overAllMerit"));
//error_log(var_export($tf->messages(true), true));

assert_search_papers($user_chair, "ovemer:4", "1");

// “4” is no longer a valid overall-merit score
$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_s01" => "Overall merit",
    "options_s01" => "1. Reject\n2. Weak reject\n3. Weak accept\nNo entry\n"
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

// So the 4 score has been removed
assert_search_papers($user_chair, "ovemer:4", "");

// revexp has not
assert_search_papers($user_chair, "revexp:2", "1");
assert_search_papers($user_chair, "has:revexp", "1");

// Stop displaying reviewer expertise
$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_s02" => "Reviewer expertise",
    "order_s02" => 0
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

// Add reviewer expertise back
$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_s02" => "Reviewer expertise",
    "options_s02" => "1. No familiarity\n2. Some familiarity\n3. Knowledgeable\n4. Expert",
    "order_s02" => 1.5
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

// It has been removed from the review
assert_search_papers($user_chair, "has:revexp", "");

// Text fields not there yet
assert_search_papers($user_chair, "has:papsum", "");
assert_search_papers($user_chair, "has:comaut", "");
assert_search_papers($user_chair, "has:compc", "");

// Check text field representation
save_review(1, $user_mgbaker, [
    "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
    "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
    "ready" => true
]);
$rrow = fetch_review($paper1, $user_mgbaker);
xassert_eqq((string) $rrow->overAllMerit, "2");
xassert_eqq((string) $rrow->reviewerQualification, "1");
xassert_eqq((string) $rrow->t01, "This is the summary\n");
xassert_eqq((string) $rrow->t02, "Comments for äuthor\n");
xassert_eqq((string) $rrow->t03, "Comments for PC\n");

assert_search_papers($user_chair, "has:papsum", "1");
assert_search_papers($user_chair, "has:comaut", "1");
assert_search_papers($user_chair, "has:compc", "1");
assert_search_papers($user_chair, "papsum:this", "1");
assert_search_papers($user_chair, "comaut:author", "1");
assert_search_papers($user_chair, "comaut:äuthor", "1");
assert_search_papers($user_chair, "papsum:author", "");
assert_search_papers($user_chair, "comaut:pc", "");
assert_search_papers($user_chair, "compc:author", "");

// Add extension fields
$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_s03" => "Score 3", "options_s03" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s03" => 2.03,
    "shortName_s04" => "Score 4", "options_s04" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s04" => 2.04,
    "shortName_s05" => "Score 5", "options_s05" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s05" => 2.05,
    "shortName_s06" => "Score 6", "options_s06" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s06" => 2.06,
    "shortName_s07" => "Score 7", "options_s07" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s07" => 2.07,
    "shortName_s08" => "Score 8", "options_s08" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s08" => 2.08,
    "shortName_s09" => "Score 9", "options_s09" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s09" => 2.09,
    "shortName_s10" => "Score 10", "options_s10" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s10" => 2.10,
    "shortName_s11" => "Score 11", "options_s11" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s11" => 2.11,
    "shortName_s12" => "Score 12", "options_s12" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s12" => 2.12,
    "shortName_s13" => "Score 13", "options_s13" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s13" => 2.13,
    "shortName_s14" => "Score 14", "options_s14" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s14" => 2.14,
    "shortName_s15" => "Score 15", "options_s15" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s15" => 2.15,
    "shortName_s16" => "Score 16", "options_s16" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s16" => 2.16,
    "shortName_t04" => "Text 4", "order_t04" => 5.04,
    "shortName_t05" => "Text 5", "order_t05" => 5.05,
    "shortName_t06" => "Text 6", "order_t06" => 5.06,
    "shortName_t07" => "Text 7", "order_t07" => 5.07,
    "shortName_t08" => "Text 8", "order_t08" => 5.08,
    "shortName_t09" => "Text 9", "order_t09" => 5.09,
    "shortName_t10" => "Text 10", "order_t10" => 5.10,
    "shortName_t11" => "Text 11", "order_t11" => 5.11
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

save_review(1, $user_mgbaker, [
    "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
    "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
    "sco3" => 1, "sco4" => 2, "sco5" => 3, "sco6" => 0,
    "sco7" => 1, "sco8" => 2, "sco9" => 3, "sco10" => 0,
    "sco11" => 1, "sco12" => 2, "sco13" => 3, "sco14" => 0,
    "sco15" => 1, "sco16" => 3,
    "tex4" => "bobcat", "tex5" => "", "tex6" => "fishercat",
    "tex7" => "tiger", "tex8" => "leopard", "tex9" => "tremolo",
    "tex10" => "underwear", "tex11" => "",
    "ready" => true
]);

assert_search_papers($user_chair, "has:sco3", "1");
assert_search_papers($user_chair, "has:sco4", "1");
assert_search_papers($user_chair, "has:sco5", "1");
assert_search_papers($user_chair, "has:sco6", "");
assert_search_papers($user_chair, "has:sco7", "1");
assert_search_papers($user_chair, "has:sco8", "1");
assert_search_papers($user_chair, "has:sco9", "1");
assert_search_papers($user_chair, "has:sco10", "");
assert_search_papers($user_chair, "has:sco11", "1");
assert_search_papers($user_chair, "has:sco12", "1");
assert_search_papers($user_chair, "has:sco13", "1");
assert_search_papers($user_chair, "has:sco14", "");
assert_search_papers($user_chair, "has:sco15", "1");
assert_search_papers($user_chair, "has:sco16", "1");
assert_search_papers($user_chair, "has:tex4", "1");
assert_search_papers($user_chair, "has:tex5", "");
assert_search_papers($user_chair, "has:tex6", "1");
assert_search_papers($user_chair, "has:tex7", "1");
assert_search_papers($user_chair, "has:tex8", "1");
assert_search_papers($user_chair, "has:tex9", "1");
assert_search_papers($user_chair, "has:tex10", "1");
assert_search_papers($user_chair, "has:tex11", "");

$rrow = fetch_review($paper1, $user_mgbaker);
xassert_eqq((string) $rrow->s16, "3");

// Remove some fields and truncate their options
$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_s15" => "Score 15", "order_s15" => 0,
    "shortName_s16" => "Score 16", "options_s16" => "1. Yes\n2. No\nNo entry\n",
    "shortName_t10" => "Text 10", "order_t10" => 0
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_s15" => "Score 15", "options_s15" => "1. Yes\n2. No\nNo entry\n", "order_s15" => 100,
    "shortName_t10" => "Text 10", "order_t10" => 101
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

$rrow = fetch_review($paper1, $user_mgbaker);
xassert(!isset($rrow->s16) || (string) $rrow->s16 === "0");
xassert(!isset($rrow->s15) || (string) $rrow->s15 === "0");
xassert(!isset($rrow->t10) || $rrow->t10 === "");

assert_search_papers($user_chair, "has:sco3", "1");
assert_search_papers($user_chair, "has:sco4", "1");
assert_search_papers($user_chair, "has:sco5", "1");
assert_search_papers($user_chair, "has:sco6", "");
assert_search_papers($user_chair, "has:sco7", "1");
assert_search_papers($user_chair, "has:sco8", "1");
assert_search_papers($user_chair, "has:sco9", "1");
assert_search_papers($user_chair, "has:sco10", "");
assert_search_papers($user_chair, "has:sco11", "1");
assert_search_papers($user_chair, "has:sco12", "1");
assert_search_papers($user_chair, "has:sco13", "1");
assert_search_papers($user_chair, "has:sco14", "");
assert_search_papers($user_chair, "has:sco15", "");
assert_search_papers($user_chair, "has:sco16", "");
assert_search_papers($user_chair, "has:tex4", "1");
assert_search_papers($user_chair, "has:tex5", "");
assert_search_papers($user_chair, "has:tex6", "1");
assert_search_papers($user_chair, "has:tex7", "1");
assert_search_papers($user_chair, "has:tex8", "1");
assert_search_papers($user_chair, "has:tex9", "1");
assert_search_papers($user_chair, "has:tex10", "");
assert_search_papers($user_chair, "has:tex11", "");

assert_search_papers($user_chair, "sco3:1", "1");
assert_search_papers($user_chair, "sco4:2", "1");
assert_search_papers($user_chair, "sco5:3", "1");
assert_search_papers($user_chair, "sco6:0", "1");
assert_search_papers($user_chair, "sco7:1", "1");
assert_search_papers($user_chair, "sco8:2", "1");
assert_search_papers($user_chair, "sco9:3", "1");
assert_search_papers($user_chair, "sco10:0", "1");
assert_search_papers($user_chair, "sco11:1", "1");
assert_search_papers($user_chair, "sco12:2", "1");
assert_search_papers($user_chair, "sco13:3", "1");
assert_search_papers($user_chair, "sco14:0", "1");
assert_search_papers($user_chair, "sco15:0", "1");
assert_search_papers($user_chair, "sco16:0", "1");
assert_search_papers($user_chair, "tex4:bobcat", "1");
assert_search_papers($user_chair, "tex5:none", "1");
assert_search_papers($user_chair, "tex6:fisher*", "1");
assert_search_papers($user_chair, "tex7:tiger", "1");
assert_search_papers($user_chair, "tex8:leopard", "1");
assert_search_papers($user_chair, "tex9:tremolo", "1");
assert_search_papers($user_chair, "tex10:none", "1");
assert_search_papers($user_chair, "tex11:none", "1");

// check handling of sfields and tfields: don't lose unchanged fields
save_review(1, $user_mgbaker, [
    "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
    "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
    "sco11" => 2, "sco16" => 1, "tex11" => "butt",
    "ready" => true
]);

assert_search_papers($user_chair, "sco3:1", "1");
assert_search_papers($user_chair, "sco4:2", "1");
assert_search_papers($user_chair, "sco5:3", "1");
assert_search_papers($user_chair, "sco6:0", "1");
assert_search_papers($user_chair, "sco7:1", "1");
assert_search_papers($user_chair, "sco8:2", "1");
assert_search_papers($user_chair, "sco9:3", "1");
assert_search_papers($user_chair, "sco10:0", "1");
assert_search_papers($user_chair, "sco11:2", "1");
assert_search_papers($user_chair, "sco12:2", "1");
assert_search_papers($user_chair, "sco13:3", "1");
assert_search_papers($user_chair, "sco14:0", "1");
assert_search_papers($user_chair, "sco15:0", "1");
assert_search_papers($user_chair, "sco16:1", "1");
assert_search_papers($user_chair, "tex4:bobcat", "1");
assert_search_papers($user_chair, "tex5:none", "1");
assert_search_papers($user_chair, "tex6:fisher*", "1");
assert_search_papers($user_chair, "tex7:tiger", "1");
assert_search_papers($user_chair, "tex8:leopard", "1");
assert_search_papers($user_chair, "tex9:tremolo", "1");
assert_search_papers($user_chair, "tex10:none", "1");
assert_search_papers($user_chair, "tex11:butt", "1");

// check handling of sfields and tfields: no changes at all
save_review(1, $user_mgbaker, [
    "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
    "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
    "sco13" => 3, "sco14" => 0, "sco15" => 0, "sco16" => 1,
    "tex4" => "bobcat", "tex5" => "", "tex6" => "fishercat", "tex7" => "tiger",
    "tex8" => "leopard", "tex9" => "tremolo", "tex10" => "", "tex11" => "butt",
    "ready" => true
]);

assert_search_papers($user_chair, "sco3:1", "1");
assert_search_papers($user_chair, "sco4:2", "1");
assert_search_papers($user_chair, "sco5:3", "1");
assert_search_papers($user_chair, "sco6:0", "1");
assert_search_papers($user_chair, "sco7:1", "1");
assert_search_papers($user_chair, "sco8:2", "1");
assert_search_papers($user_chair, "sco9:3", "1");
assert_search_papers($user_chair, "sco10:0", "1");
assert_search_papers($user_chair, "sco11:2", "1");
assert_search_papers($user_chair, "sco12:2", "1");
assert_search_papers($user_chair, "sco13:3", "1");
assert_search_papers($user_chair, "sco14:0", "1");
assert_search_papers($user_chair, "sco15:0", "1");
assert_search_papers($user_chair, "sco16:1", "1");
assert_search_papers($user_chair, "tex4:bobcat", "1");
assert_search_papers($user_chair, "tex5:none", "1");
assert_search_papers($user_chair, "tex6:fisher*", "1");
assert_search_papers($user_chair, "tex7:tiger", "1");
assert_search_papers($user_chair, "tex8:leopard", "1");
assert_search_papers($user_chair, "tex9:tremolo", "1");
assert_search_papers($user_chair, "tex10:none", "1");
assert_search_papers($user_chair, "tex11:butt", "1");

// check handling of sfields and tfields: clear extension fields
save_review(1, $user_mgbaker, [
    "ovemer" => 2, "revexp" => 1, "papsum" => "",
    "comaut" => "", "compc" => "", "sco12" => 0,
    "sco13" => 0, "sco14" => 0, "sco15" => 0, "sco16" => 0,
    "tex4" => "", "tex5" => "", "tex6" => "", "tex7" => "",
    "tex8" => "", "tex9" => "", "tex10" => "", "tex11" => "",
    "ready" => true
]);

$rrow = fetch_review($paper1, $user_mgbaker);
xassert(!$rrow->sfields);
xassert(!$rrow->tfields);

save_review(1, $user_mgbaker, [
    "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
    "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
    "sco3" => 1, "sco4" => 2, "sco5" => 3, "sco6" => 0, "sco7" => 1,
    "sco8" => 2, "sco9" => 3, "sco10" => 0, "sco11" => 2,
    "sco12" => 2, "sco13" => 3, "sco14" => 0, "sco15" => 0, "sco16" => 1,
    "tex4" => "bobcat", "tex5" => "", "tex6" => "fishercat", "tex7" => "tiger",
    "tex8" => "leopard", "tex9" => "tremolo", "tex10" => "", "tex11" => "butt",
    "ready" => true
]);

assert_search_papers($user_chair, "sco3:1", "1");
assert_search_papers($user_chair, "sco4:2", "1");
assert_search_papers($user_chair, "sco5:3", "1");
assert_search_papers($user_chair, "sco6:0", "1");
assert_search_papers($user_chair, "sco7:1", "1");
assert_search_papers($user_chair, "sco8:2", "1");
assert_search_papers($user_chair, "sco9:3", "1");
assert_search_papers($user_chair, "sco10:0", "1");
assert_search_papers($user_chair, "sco11:2", "1");
assert_search_papers($user_chair, "sco12:2", "1");
assert_search_papers($user_chair, "sco13:3", "1");
assert_search_papers($user_chair, "sco14:0", "1");
assert_search_papers($user_chair, "sco15:0", "1");
assert_search_papers($user_chair, "sco16:1", "1");
assert_search_papers($user_chair, "tex4:bobcat", "1");
assert_search_papers($user_chair, "tex5:none", "1");
assert_search_papers($user_chair, "tex6:fisher*", "1");
assert_search_papers($user_chair, "tex7:tiger", "1");
assert_search_papers($user_chair, "tex8:leopard", "1");
assert_search_papers($user_chair, "tex9:tremolo", "1");
assert_search_papers($user_chair, "tex10:none", "1");
assert_search_papers($user_chair, "tex11:butt", "1");

xassert_exit();
