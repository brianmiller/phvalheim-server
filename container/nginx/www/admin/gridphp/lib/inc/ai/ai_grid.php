<?php 
/**
 * Grid 4 PHP Component
 *
 * @author Abu Ghufran <gridphp@gmail.com> - https://www.gridphp.com
 * @version 3.1 build 20250829-0000
 * @license: see license.txt included in package
 */

define("AAACH","We're unable to generate response at this time. Please try again."); define("AAACG","Please enter a valid api key in configuration file."); define("AAACE","invalid_api_key"); define("AAACC","suggest_questions:"); define("AAABZ","get_insight output:"); define("AAABX","get_insight:"); define("AAABV","Error: No valid JSON found"); define("AAABU","fdb38ad8c760ac1619bf9ef5917bc0ebe:"); define("AAABR","str_getcsv"); define("AAABO","make_json_readable output:"); define("AAABN","make_json_readable:"); define("AAABL","empty json input"); define("AAABJ","message"); define("AAABI","choices"); define("AAABH","ff890b9052a455c13fdfabdb87ce1e0ae reponse:"); define("AAABF","Content-Type: application/json"); define("AAABD","json_object"); define("AAABB","type"); define("AAABA","response_format"); define("AAAAZ","json"); define("AAAAY","stop"); define("AAAAV","top_p"); define("AAAAU","max_completion_tokens"); define("AAAAS","temperature"); define("AAAAP","content"); define("AAAAN","user"); define("AAAAL","role"); define("AAAAI","messages"); define("AAAAF","meta-llama/llama-4-scout-17b-16e-instruct"); define("AAAAD","model"); define("AAAAB","https://api.groq.com/openai/v1/chat/completions"); define("ZZZZ","");  ?><?php
class ai_grid {
static $v15d61712450a686a7f365adf4fef581f = ZZZZ; static $key = ZZZZ; static function ff890b9052a455c13fdfabdb87ce1e0ae($v4ae35dbb42614d2429b7d6d181a950bb) {
$vaa8106611bcfe43fec48e6d1d371de52 = AAAAB; $v39802830831bed188884e193d8465226 = ai_grid::$key; $vdf988dd464bd288c5031b2a4e27ee33d = [
AAAAD => AAAAF,
AAAAI => [[AAAAL => AAAAN, AAAAP => $v4ae35dbb42614d2429b7d6d181a950bb]],
AAAAS => 1,
AAAAU => 1024,
AAAAV => 1,
AAAAY => null
]; if (ai_grid::$v15d61712450a686a7f365adf4fef581f == AAAAZ)
$vdf988dd464bd288c5031b2a4e27ee33d[AAABA] = [AAABB=>AAABD]; $vcf74b4e567c8abaff4bcc94f374cbf8b = json_encode($vdf988dd464bd288c5031b2a4e27ee33d); $vd88fc6edf21ea464d35ff76288b84103 = curl_init(); curl_setopt($vd88fc6edf21ea464d35ff76288b84103, CURLOPT_URL, $vaa8106611bcfe43fec48e6d1d371de52); curl_setopt($vd88fc6edf21ea464d35ff76288b84103, CURLOPT_RETURNTRANSFER, 1); curl_setopt($vd88fc6edf21ea464d35ff76288b84103, CURLOPT_POST, 1); curl_setopt($vd88fc6edf21ea464d35ff76288b84103, CURLOPT_HTTPHEADER, [
AAABF,
"Authorization: Bearer $v39802830831bed188884e193d8465226"
]); curl_setopt($vd88fc6edf21ea464d35ff76288b84103, CURLOPT_POSTFIELDS, $vcf74b4e567c8abaff4bcc94f374cbf8b); curl_setopt($vd88fc6edf21ea464d35ff76288b84103, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($vd88fc6edf21ea464d35ff76288b84103, CURLOPT_SSL_VERIFYHOST, false); $vd1fc8eaf36937be0c3ba8cfe0a2c1bfe = curl_exec($vd88fc6edf21ea464d35ff76288b84103); curl_close($vd88fc6edf21ea464d35ff76288b84103); error_log(AAABH.$vd1fc8eaf36937be0c3ba8cfe0a2c1bfe); return json_decode($vd1fc8eaf36937be0c3ba8cfe0a2c1bfe, true)[AAABI][0][AAABJ][AAAAP] ?? $vd1fc8eaf36937be0c3ba8cfe0a2c1bfe; } 
static function make_json_readable($v466deec76ecdf5fca6d38571f6324d54, $v5494af1f14a8c19939968c3e9e2d4f79) {
if (empty(json_decode($v466deec76ecdf5fca6d38571f6324d54,true))) {
$vb4a88417b3d0170d754c647c30b7216a = new stdClass(); $vb4a88417b3d0170d754c647c30b7216a->error = AAABL; ai_grid::f5c1479a0fb821237d662b94a18ba3233($vb4a88417b3d0170d754c647c30b7216a); return $vb4a88417b3d0170d754c647c30b7216a; } 
$v4ae35dbb42614d2429b7d6d181a950bb = "Query was '$v5494af1f14a8c19939968c3e9e2d4f79'.
Combine this json data returned from database to human readable reponse:\n\n$v466deec76ecdf5fca6d38571f6324d54. 
If input json data is empty, throw error. 
Skip empty record in json.
Take best answer from the json data.
For single line result, don't use bullets and paragraph.
Try to display long paragraph result in html ul/li tag.
Set numeric values in html strong tag with royal blue color. 
Round of prices in decimals to 2 places.
Dont mention table ID columns in summary.
Give response exactly in this json format: {
'result': { 'text': 'ai-response' } }"; ai_grid::$v15d61712450a686a7f365adf4fef581f = AAAAZ; error_log(AAABN.$v4ae35dbb42614d2429b7d6d181a950bb); $vb4a88417b3d0170d754c647c30b7216a = ai_grid::f5ed33f7008771c9d49e3716aeaeca581($v4ae35dbb42614d2429b7d6d181a950bb); error_log(AAABO.($vb4a88417b3d0170d754c647c30b7216a)); $vb4a88417b3d0170d754c647c30b7216a = json_decode($vb4a88417b3d0170d754c647c30b7216a); if ($vb4a88417b3d0170d754c647c30b7216a->error) 
ai_grid::f5c1479a0fb821237d662b94a18ba3233($vb4a88417b3d0170d754c647c30b7216a); return $vb4a88417b3d0170d754c647c30b7216a; } 
static function summarize_csv_with_groq($v0a14fae61dba08f4b3fb2cbb8c78014f) {
$vdf347a373b8f92aa0ae3dd920a5ec2f6 = array_map(AAABR, explode("\n", $v0a14fae61dba08f4b3fb2cbb8c78014f)); $v099fb995346f31c749f6e40db0f395e3 = array_shift($vdf347a373b8f92aa0ae3dd920a5ec2f6); $v8d777f385d3dfec8815d20f7496026dc = []; foreach ($vdf347a373b8f92aa0ae3dd920a5ec2f6 as $vf1965a857bc285d26fe22023aa5ab50d) {
if (count($vf1965a857bc285d26fe22023aa5ab50d) == count($v099fb995346f31c749f6e40db0f395e3)) {
$v8d777f385d3dfec8815d20f7496026dc[] = array_combine($v099fb995346f31c749f6e40db0f395e3, $vf1965a857bc285d26fe22023aa5ab50d); } } 
$vfebb87e8c2e89a709c78a924d81c0f35 = json_encode($v8d777f385d3dfec8815d20f7496026dc, JSON_PRETTY_PRINT); $v4ae35dbb42614d2429b7d6d181a950bb = "Summarize the following CSV file :\n\n$vfebb87e8c2e89a709c78a924d81c0f35"; return ai_grid::f5ed33f7008771c9d49e3716aeaeca581($v4ae35dbb42614d2429b7d6d181a950bb); } 
static function f5ed33f7008771c9d49e3716aeaeca581($v4ae35dbb42614d2429b7d6d181a950bb) {
return ai_grid::ff890b9052a455c13fdfabdb87ce1e0ae($v4ae35dbb42614d2429b7d6d181a950bb); } 
static function fdb38ad8c760ac1619bf9ef5917bc0ebe($vd1fc8eaf36937be0c3ba8cfe0a2c1bfe) {
error_log(AAABU.$vd1fc8eaf36937be0c3ba8cfe0a2c1bfe); preg_match('~\{(?:[^{}]|(?R))*\}~', $vd1fc8eaf36937be0c3ba8cfe0a2c1bfe, $v9c28d32df234037773be184dbdafc274); return $v9c28d32df234037773be184dbdafc274[0] ?? AAABV; } 
static function get_json_filters_by_nlp($vd05b6ed7d2345020440df396d6da7f73,$table,$v5494af1f14a8c19939968c3e9e2d4f79) {
$v4ae35dbb42614d2429b7d6d181a950bb = "Using following sql table '$table' and fields '$vd05b6ed7d2345020440df396d6da7f73',
Convert the following natural language query into structured JSON as well as SQL query:
Query: '$v5494af1f14a8c19939968c3e9e2d4f79'. 
Return the WHERE clause & ORDER BY clause output in ONLY this JSON format: { 'filters': [\n {\"field\": \"field_name\", \"op\": \"op\", \"data\": \"value\"},\n {\"field\": \"field_name\", \"op\": \"op\", \"data\": \"value\"}\n], 'order':{\"field\": \"field_name\", \"sort\": \"sort_order\"}, 'explanation':'short text explaining the filters conditions and prefix with (Filtering ...). Don't tell about sorting.'}.
'op' in json can be one of these: <,<=,>,>=,=,!=,like.
Don't return % in json data for like query.
For single term, always use like operator.
Try to find the single term in all possible table fields.
Don't use database field name and terms in explanation, Use user friendly name.
If no field is found, search all fields with like operator for the term.
"; $vb4a88417b3d0170d754c647c30b7216a = ai_grid::f5ed33f7008771c9d49e3716aeaeca581($v4ae35dbb42614d2429b7d6d181a950bb); return ai_grid::fdb38ad8c760ac1619bf9ef5917bc0ebe($vb4a88417b3d0170d754c647c30b7216a); } 
static function get_insights($vd05b6ed7d2345020440df396d6da7f73,$vac5c74b64b4b8352ef2f181affb5ac2a,$v5494af1f14a8c19939968c3e9e2d4f79) {
$vd77d5e503ad1439f585ac494268b351b = PHPGRID_DBTYPE; $v4ae35dbb42614d2429b7d6d181a950bb = "Based on sql query '$vac5c74b64b4b8352ef2f181affb5ac2a' and fields '$vd05b6ed7d2345020440df396d6da7f73'. Keep table alias and joins as it source sql. 
Also use fields with table name alias. 
Only use these fields and don't assume any new field. 
If there are entity id and name both present in fields, try to show result with name.
Convert the following natural language query into $vd77d5e503ad1439f585ac494268b351b latest version supported select query:
Query: '$v5494af1f14a8c19939968c3e9e2d4f79'. 
For multiple records in result, limit sql query to best 10 records and prefer giving aggregate result.
Round off numeric values to zero places and don't mention in explanation.
Return in this json format: {
'results':
[ {'sql':'...','explanation':'...'}, {'sql':'...','explanation':'...'} ]
} 
"; error_log(AAABX.$v4ae35dbb42614d2429b7d6d181a950bb); ai_grid::$v15d61712450a686a7f365adf4fef581f = AAAAZ; $vb4a88417b3d0170d754c647c30b7216a = ai_grid::f5ed33f7008771c9d49e3716aeaeca581($v4ae35dbb42614d2429b7d6d181a950bb); error_log(AAABZ.$vb4a88417b3d0170d754c647c30b7216a); $vb4a88417b3d0170d754c647c30b7216a = json_decode($vb4a88417b3d0170d754c647c30b7216a); if ($vb4a88417b3d0170d754c647c30b7216a->error) 
ai_grid::f5c1479a0fb821237d662b94a18ba3233($vb4a88417b3d0170d754c647c30b7216a); return $vb4a88417b3d0170d754c647c30b7216a; } 
static function suggest_questions($vd05b6ed7d2345020440df396d6da7f73,$vac5c74b64b4b8352ef2f181affb5ac2a) {
$v4ae35dbb42614d2429b7d6d181a950bb = "Based on sql query '$vac5c74b64b4b8352ef2f181affb5ac2a' and fields '$vd05b6ed7d2345020440df396d6da7f73'.
Suggest the questions that can be asked to summarize data.
Don't suggest question which needs an input.
Don't suggest question where expected response is large text.
Limit to 5 questions.
Return in this json format: {
'results':
[ {'question':'question-content'}, {'question':'question-content'} ]
}"; error_log(AAACC.$v4ae35dbb42614d2429b7d6d181a950bb); ai_grid::$v15d61712450a686a7f365adf4fef581f = AAAAZ; $vb4a88417b3d0170d754c647c30b7216a = ai_grid::f5ed33f7008771c9d49e3716aeaeca581($v4ae35dbb42614d2429b7d6d181a950bb); $vb4a88417b3d0170d754c647c30b7216a = json_decode($vb4a88417b3d0170d754c647c30b7216a); if ($vb4a88417b3d0170d754c647c30b7216a->error) 
ai_grid::f5c1479a0fb821237d662b94a18ba3233($vb4a88417b3d0170d754c647c30b7216a); return $vb4a88417b3d0170d754c647c30b7216a; } 
static function f5c1479a0fb821237d662b94a18ba3233(&$vd1fc8eaf36937be0c3ba8cfe0a2c1bfe) {
if ($vd1fc8eaf36937be0c3ba8cfe0a2c1bfe->error) {
if ($vd1fc8eaf36937be0c3ba8cfe0a2c1bfe->error->code == AAACE)
$vd1fc8eaf36937be0c3ba8cfe0a2c1bfe->error = AAACG; else
$vd1fc8eaf36937be0c3ba8cfe0a2c1bfe->error = AAACH; } } }?>