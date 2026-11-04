<?php
// requires: container
// Run only in an isolated disposable database; changes roll back and files live in /tmp.
$pdo = new PDO('mysql:host=localhost;dbname=phvalheim','phvalheim_user','phvalheim_secretpassword',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
define('VIBES_ROOT',sys_get_temp_dir().'/phv-vibes-summary-'.bin2hex(random_bytes(5)));
require '/opt/stateless/nginx/www/includes/vibes_summary.php';
$n=0;
function ck($ok,$label) { global $n; if (!$ok) throw new RuntimeException($label); $n++; echo "PASS: $label\n"; }
function put($path,$value) { @mkdir(dirname($path),0777,true);file_put_contents($path,$value); }
$pdo->beginTransaction();
try {
    $pdo->exec('UPDATE settings SET vibesInstalled=0');
    $base=phvVibesLibrarySummary($pdo);
    ck($base['available'] && $base['state']==='ok' && !$base['installed'],'uninstalled library is quiet');
    $pdo->exec("INSERT INTO hv_projects(slug,name,namespace) VALUES ('summary-oracle','SummaryOracle','Oracle')");
    $pdo->exec("INSERT INTO mods(source,owner,name,full_name) VALUES ('vibes','Oracle','SummaryOracle','Oracle-SummaryOracle')");$mid=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO mod_versions(mod_id,version,full_name,download_url,is_active) VALUES ($mid,'1.0.0','Oracle-SummaryOracle-1.0.0','fixture',1),($mid,'0.1.0','Oracle-SummaryOracle-0.1.0','fixture',0)");
    $pdo->exec("INSERT INTO world_mods(world_id,mod_id) VALUES (4294967000,$mid)");
    $v=phvVibesLibrarySummary($pdo);
    ck($v['projects']===$base['projects']+1 && $v['mods']===$base['mods']+1 && $v['versions']===$base['versions']+1 && $v['worlds']===$base['worlds']+1,'actual projects, active published versions and world usage retained while off');
    $pdo->exec('UPDATE settings SET vibesInstalled=1');
    ck(phvVibesLibrarySummary($pdo)['state']==='warn','installed but missing Build Pak warns');
    put(vibesPakCurrent().'/manifest.json',json_encode(['pakFormat'=>1,'base'=>'jammy','version'=>'fixture']));
    ck(phvVibesLibrarySummary($pdo)['state']==='warn','manifest alone cannot claim executable tools');
    foreach (['/dotnet/dotnet','/git/usr/bin/git'] as $f) {put(vibesPakCurrent().$f,'fixture');chmod(vibesPakCurrent().$f,0755);}
    ck(phvVibesLibrarySummary($pdo)['state']==='unknown','no harvest is setup needed, never pending catalogue sync');
    put(vibesLibsDir().'/assembly_valheim.dll','fixture');
    put(vibesLibsDir().'/manifest.json',json_encode(['files'=>['assembly_valheim.dll'=>'fixture'],'sourceWorld'=>'FixtureWorld','gameBuild'=>'123','harvestedAt'=>'2026-01-01T00:00:00Z']));
    $v=phvVibesLibrarySummary($pdo);
    ck($v['state']==='ok' && $v['libs']['assemblies']===1 && $v['libs']['gameBuild']==='123','installed tools and readable references ready with harvest details');
    unlink(vibesLibsDir().'/assembly_valheim.dll');
    ck(phvVibesLibrarySummary($pdo)['state']==='warn','missing harvested DLL warns');
    $pdo->exec("INSERT INTO hv_jobs(kind,status,step,progress) VALUES ('harvest','running','Copying',40)");
    $v=phvVibesLibrarySummary($pdo);
    ck($v['state']==='unknown' && $v['jobs'][0]['step']==='Copying','active harvest reports progress without a false ready claim');
    ck(!str_contains(json_encode($v),'token') && !str_contains(json_encode($v),'settings'),'summary excludes credentials and settings');
} finally {
    $pdo->rollBack();
    if (is_dir(VIBES_ROOT)) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(VIBES_ROOT,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir(VIBES_ROOT);
    }
}
echo "$n checks passed\n";
