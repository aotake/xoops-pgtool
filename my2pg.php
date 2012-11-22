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

        // バッククォートを除去
        $sql = str_replace("`", "", $sql);

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
        if(preg_match("/CREATE TABLE\s*`?([^\(`]+)`?\s*\(.*/", ltrim($buffer), $m)){
            $table = trim($m[1]);
        }

        // ENGINE=MyISAM があれば削除
        $buffer = preg_replace("/(.+)ENGINE\s*=\s*MyISAM(.+)/", "\\1\\2", $buffer);
        // TYPE=MyISAM があれば削除（たぶん古いタイプ）
        $buffer = preg_replace("/(.+)TYPE\s*=\s*MyISAM(.+)/", "\\1\\2", $buffer);

        // varchar(xx) binary は varchar(xx) に
        $buffer = preg_replace("/(.+)\s(varchar\(\d+\))\s+binary\s+(.*)/", "\\1 \\2 \\3", $buffer);


        // int(xx) unsigned は bigint に
        $buffer = preg_replace("/(.+)\s+(int)\(\d+\) unsigned(.*)/", "\\1 bigint\\3", $buffer);

        // mediumint(xx) unsigned は int に
        $buffer = preg_replace("/(.+)\s+(mediumint)\(\d+\) unsigned(.*)/", "\\1 int\\3", $buffer);

        // mediumint unsigned は int に
        $buffer = preg_replace("/(.+)\s+(mediumint)\s+unsigned(.*)/", "\\1 int \\3", $buffer);

        // smallint(xx) unsigned は int に
        $buffer = preg_replace("/(.+)\s(smallint)\(\d+\) unsigned(.*)/", "\\1 int \\3", $buffer);

        // tinyint(xx) unsigned は smallint に
        $buffer = preg_replace("/(.+)\s(tinyint)\(\d+\) unsigned(.*)/", "\\1 smallint \\3", $buffer);

        // tinyint unsigned は smallint に
        $buffer = preg_replace("/(.+)\s(tinyint)\s+unsigned(.*)/", "\\1 smallint \\3", $buffer);

        // int(xx), mediumint(xx) は int に
        $buffer = preg_replace("/(.+)\s+(int|mediumint)\(\d+\)(.*)/", "\\1 int\\3", $buffer);

        // mediumint は int に
        $buffer = preg_replace("/(.+)\s+(mediumint)\s+(.*)/", "\\1 int \\3", $buffer);

        // smallint(xx), tinyint(xx) は smallint に
        $buffer = preg_replace("/(.+)\s(smallint|tinyint)\(\d+\)(.*)/", "\\1 smallint\\3", $buffer);

        // tinyint は smallint に
        $buffer = preg_replace("/(.+)\s(tinyint)\s+(.*)/", "\\1 smallint \\3", $buffer);





        // 16進数表記のしかたを変更 mysql=> 0xFFFF, pgsq => X'FFFF'::int
        $buffer = preg_replace("/0x([0-9a-fA-F]+)/", "X'\\1'::int", $buffer);

        // unsigned は削除
        //$buffer = preg_replace("/(.+)(unsigned)(.*)/", "\\1\\3", $buffer);

        // mediumtext は text に
        $buffer = preg_replace("/(.+)\s+(mediumtext)(.*)/", "\\1 text\\3", $buffer);

        // binary,blob,tinyblob,mediumblob,longblob は bytea に
        $buffer = preg_replace("/(.+)\s+(binary|tinyblob|mediumblob|longblob|blob)(.*)/", "\\1 bytea\\3", $buffer);

        // binary(N) は bytea に
        $buffer = preg_replace("/(.+)\s+(binary)\(\d+\)(.*)/", "\\1 bytea\\3", $buffer);

        // auto_increment は serial に変更,  serial キーのカラムを保存
        $buffer = preg_replace("/([^\s]+)\s+(int|smallint)(.*)auto_increment/", "\\1 serial", $buffer);
        if(preg_match("/([^\s]+)\s+serial/", $buffer, $m)){
            $primary_keys[$table] = $m[1];
        }

        // bigint の auto_increment は bigserial に変更,  serial キーのカラムを保存
        $buffer = preg_replace("/([^\s]+)\s+(bigint)(.*)auto_increment/", "\\1 bigserial", $buffer);
        if(preg_match("/([^\s]+)\s+bigserial/", $buffer, $m)){
            $primary_keys[$table] = $m[1];
        }


        // 16進数表記の変更 0xXXXX -> X'XXXX'::integer （註：文字列型のとき問題）
        $hex_pattern = "(.+)0x([0-9a-fA-F]+)(.*)";
        $buffer = preg_replace("/$hex_pattern/", "\\1X'\\2'::integer\\3", $buffer);

        // UNIX_TIMESTAMP() -> extract(epoch from now())
        $buffer = str_replace("UNIX_TIMESTAMP()", "extract(epoch from now())", $buffer);

        // KEY(xxx) は削除。CREATE INDEX 用に管理。
        $key_pattern = "\s*KEY\s*\((.+)\)";
        if(preg_match("/$key_pattern/", $buffer, $m) && !preg_match("/(PRIMARY|UNIQUE)/", $buffer)){
            $index[$table][] = "\"".str_replace(",", "\",\"", $m[1])."\"";
            continue;
        }

        // KEY keyname (xxx) は削除。CREATE INDEX 用に管理。
        $key_pattern = "\s*KEY\s*`?([^`]+)`?\s*\(`?([^`]+)`?\)";
        if(preg_match("/$key_pattern/", $buffer, $m) && !preg_match("/(PRIMARY|UNIQUE)/", $buffer)){
            //$index_with_name[$table][$m[1]] = "\"".$m[2]."\"";
            $index[$table][] = "\"".$m[2]."\"";
            continue;
        }

        // KEY keyname (xxx(NN)) は削除。CREATE INDEX 用に管理。
        //   MySQL は text 型は長さをしていしてvarchar的なインデックスがつくれるらしい
        //   PostgreSQL は text 型にもインデックスがはれるので (NN) の部分を除去する
        $key_pattern = "\s*KEY\s*`?([^`]+)`?\s*\("
            ."("
            .   "`?([^`]+)`?\(\d+\)"
            .")"
            ."("
            .   "\s*,\s*`?([^`]+)`?\(\d+\)"
            ."){0,}"
            ."\)";
        if(preg_match("/$key_pattern/", $buffer, $m) && !preg_match("/(PRIMARY|UNIQUE)/", $buffer)){
            $keys = null;
            for($i = 3; $i < count($m); $i++){
                if($i % 2 == 1){
                    if($keys == ""){
                        $keys = "\"".$m[$i]."\"";
                    } else {
                        $keys .= ",\"".$m[$i]."\"";
                    }
                }
            }
            $index_with_name[$table][$m[1]] = $keys;
            continue;
        }

        // UNIQUE KEY(xxx) は削除。CREATE INDEX 用に管理。
        $uniq_pattern = "\s*UNIQUE KEY\s*\((.+)\)";
        if( preg_match("/$uniq_pattern/", $buffer, $m) ){
            $unique[$table][] = "\"".$m[1]."\"";
            continue;
        }
        // UNIQUE KEY key_name (xxx) は削除。CREATE INDEX 用に管理。
        $uniq_pattern = "\s*UNIQUE KEY\s*`?([^`]+)`?\s*\(`?([^`]+)`?\)";
        if( preg_match("/$uniq_pattern/", $buffer, $m) ){
            //$unique_with_name[$table][$m[1]] = "\"".$m[2]."\"";
            $unique[$table][] = "\"".$m[2]."\"";
            continue;
        }

        // カラム名に " をつける
        if(preg_match("/(\s*)([^\s\"]+)\s+(.+)/", $buffer, $m)){
            if(!in_array(trim($m[2]), array("CREATE", "INSERT", "ALTER", "UPDATE", "PRIMARY",")"))){
                $buffer = $m[1]."\"".$m[2]."\" ". $m[3];
            }
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
if(isset($index)){
    foreach($index as $tbl => $key_array){
        foreach($key_array as $k){
            $sql = "CREATE INDEX \"".$tbl."_".str_replace(",", "_", str_replace('"', '', $k))."_idx\" ON \"$tbl\" ($k);\n";
            // バッククォートを除去
            $sql = str_replace("`", "", $sql);
            print $sql;
        }
    }
}
// CREATE INDEX 文(名前付き）
if(isset($index_with_name)){
    foreach($index_with_name as $tbl => $key_array){
        foreach($key_array as $kname => $kval){
            $sql = "CREATE INDEX \"".$kname."\" ON \"$tbl\" ($kval);\n";
            // バッククォートを除去
            $sql = str_replace("`", "", $sql);
            print $sql;
        }
    }
}
// CREATE UNIQUE INDEX 文
if(isset($unique)){
    foreach($unique as $tbl => $key_array){
        foreach($key_array as $k){
            $ks = str_replace("\"", "", $k); // 付け足してしまった " を一端除去
            $ks = explode(",", $ks);         // カンマで分割
            $sql = "CREATE UNIQUE INDEX ".$tbl."_".implode("_", $ks)."_idx ON $tbl (\"".implode("\",\"",$ks)."\");\n";
            // バッククォートを除去
            $sql = str_replace("`", "", $sql);
            print $sql;
        }
    }
}
// CREATE UNIQUE INDEX 文（名前付き）
if(isset($unique_with_name)){
    foreach($unique_with_name as $tbl => $key_array){
        foreach($key_array as $k => $v){
            $sql = "CREATE UNIQUE INDEX \"".$k."\" ON \"$tbl\" ($v);\n";
            // バッククォートを除去
            $sql = str_replace("`", "", $sql);
            print $sql;
        }
    }
}
//print_r($unique);
