#!/usr/local/bin/php
<?php
/**
 * Mysql 用 SQL ファイルを PostgreSQL 用に変換する
 *
 * Usage:
 *      $ php my2pg.php < MODULE_TOP_DIR/sql/mysql.sql > MODULE_TOP_DIR/sql/pdo_pgsql.sql
 *
 */

function printSql(&$str)
{
    if(preg_match("/^\s*(CREATE TABLE|DROP TABLE|ALTER TABLE)/", $str[0])){

        // 連結
        $sql = implode(",\n", $str)."\n";

        // CREATE TABLE hoge (, id int .....) のようなやつから最初の , を除去
        $sql = preg_replace("/([^\(]+)\(\s*,(.+)/siU", "\\1(\\2", $sql);
        // CREATE TABLE hoge (id int .....  aaa int, ); のようなやつから最後の , を除去
        $sql = preg_replace("/(.+),\n\s*\)\s*;$/siU", "\\1\n);", $sql);

        print $sql."\n";
        $str = array();

    }
    else if(preg_match("/^\s*(INSERT|UPdATE|DELETE)/", $str[0])){

        print implode("\n", $str)."\n";
        $str = array();

    }
}

$buffer = null; // stdin から１行取得
$str = array();    // 変換後の文字列を連結
$table = null;
$primary_keys = array(); // auto_increment のところを保持

$handle = @fopen("php://stdin", "r");
if ($handle) {
    while (($buffer = fgets($handle, 4096)) !== false) {
        if(preg_match("/^(#|\n)/", $buffer)) continue;
        if(preg_match("/^\s*--/", $buffer)) continue;

        // 右側改行を除去
        $buffer = rtrim($buffer);

        // INSERT 文、UPDATE 文、DELETE 文は１行にまとめる
        if (preg_match("/(INSERT|UPDATE|DELETE)/", $buffer)) {
            if (!preg_match("/\)\s*;\s*$/",$buffer)) {

                do {
                    $buffer .= " ".trim( fgets($handle, 4096) );
                } while( !preg_match("/\)\s*;\s*$/",$buffer) );

            }

            // 主キーと思われるところは省く
            if(array_key_exists($table, $primary_keys)){
                $pkey = $primary_keys[$table];
                $pat = "(INSERT INTO [^\(]+\()".$pkey."\s*,(\s*[^\)]+\)\s*VALUES\s*\()0,(.+)";
                if(preg_match("/$pat/", $buffer, $m)){
                    $buffer = $m[1]." ".$m[2]." ".$m[3];
                }
            }
        }

        // 出力条件
        // CREATE|ALTER|DROP が行頭のラインがきたら、そこまでの $str を出力してクリアする
        if(preg_match("/^\s*(CREATE|ALTER|DROP|INSERT|UPDATE|DELETE)/", $buffer) && $str){
            printSql($str);
        }

        // テーブル名取得
        if(preg_match("/CREATE TABLE\s*([^\(]+)\(.*/", ltrim($buffer), $m)){
            $table = trim($m[1]);
        }

        // ENGINE=MyISAM があれば削除
        $buffer = preg_replace("/(.+)ENGINE\s*=\s*MyISAM(.+)/", "\\1\\2", $buffer);

        // int(xx), mediumint(xx) は int に
        $buffer = preg_replace("/(.+)\s+(int|mediumint)\(\d+\)(.*)/", "\\1 int\\3", $buffer);

        // mediumint は int に
        $buffer = preg_replace("/(.+)\s+(mediumint)\s+(.*)/", "\\1 int \\3", $buffer);

        // smallint(xx), tinyint(xx) は smallint に
        $buffer = preg_replace("/(.+)\s(smallint|tinyint)\(\d+\)(.*)/", "\\1 smallint\\3", $buffer);

        // tinyint は smallint に
        $buffer = preg_replace("/(.+)\s(tinyint)\s+(.*)/", "\\1 smallint \\3", $buffer);

        // unsigned は削除
        $buffer = preg_replace("/(.+)(unsigned)(.*)/", "\\1\\3", $buffer);

        // mediumtext は text に
        $buffer = preg_replace("/(.+)\s+(mediumtext)(.*)/", "\\1 text\\3", $buffer);

        // auto_increment は serial に変更,  serial キーのカラムを保存
        $buffer = preg_replace("/([^\s]+)\s+(int|smallint)(.*)auto_increment/", "\\1 serial", $buffer);
        if(preg_match("/([^\s]+)\s+serial/", $buffer, $m)){
            $primary_keys[$table] = $m[1];
        }

        // KEY(xxx) は削除。CREATE INDEX 用に管理。
        $key_pattern = "\s*KEY\s*\((.+)\)";
        if(preg_match("/$key_pattern/", $buffer, $m) && !preg_match("/UNIQUE/", $buffer)){
            $index[$table][] = $m[1];
            continue;
        }

        // UNIQUE KEY(xxx) は削除。CREATE INDEX 用に管理。
        $uniq_pattern = "\s*UNIQUE KEY\s*\((.+)\)";
        if( preg_replace("/$uniq_pattern/", $buffer, $m) ){
            $unique[$table][] = $m[1];
            continue;
        }

        // 行末の "," は削除する（出力時に連結）
        $buffer = preg_replace("/(.+),$/", "\\1", $buffer);

        if(preg_match("/^\s+$/", $buffer)) continue;

        $str[] = $buffer;
    }
    if (!feof($handle)) {
        echo "Error: unexpected fgets() fail\n";
    }
    fclose($handle);
}

// 残りの CREATE TABLE 文
printSql($str);

// CREATE INDEX 文
foreach($index as $tbl => $key_array){
    foreach($key_array as $k){
        print "CREATE INDEX ".$tbl."_".str_replace(",", "_", $k)."_idx ON $tbl ($k);\n";
    }
}
// CREATE UNIQUE INDEX 文
foreach($unique as $tbl => $key_array){
    foreach($key_array as $k){
        print "CREATE UNIQUE INDEX ".$tbl."_".str_replace(",", "_", $k)."_idx ON $tbl ($k);\n";
    }
}
//print_r($unique);
