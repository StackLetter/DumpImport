#!/usr/bin/env php
<?php

use Neevo\Manager;
use Prewk\XmlStringStreamer;

require_once __DIR__ . '/vendor/autoload.php';



class DumpImport{

    public $config;
    public $dataDir;
    public $db;

    private $hashTables;

    public function __construct(array $config, $dataDir, Manager $db){
        $this->config = $config;
        $this->dataDir = $dataDir;
        $this->db = $db;
    }


    public function run($job){
        if(!isset($this->config['jobs'][$job])){
            fwrite(STDERR, "No such job '$job' in config\n");
            exit(1);
        }
        $settings = $this->config['jobs'][$job];
        $callback = [$this, $settings['callback']];
        call_user_func($callback, $settings['input'], $settings);
    }


    public function processType($val, $type, $node){
        if($val === NULL && substr($type, 0, 5) !== 'NULL.'){
            return '\\N';
        }
        if($type == 'int'){
            return (int) $val;
        } elseif($type == 'string'){
            return str_replace(
                ["\r", "\n", "\t", "\f", "\v"],
                ['\\r', '\\n', '\\t', '\\f', '\\v'],
                str_replace('\\', '\\\\', $val)
            );
        } elseif($type == 'timestamp'){
            return str_replace('T', ' ', $val);
        }

        // Foreign keys
        elseif($type == 'X.user_id'){
            return $this->getExternalId('users', $val);
        } elseif($type == 'X.question_id'){
            return $this->getExternalId('questions', $val);
        } elseif($type == 'X.answer_id'){
            return $this->getExternalId('answers', $val);
        } elseif($type == 'X.badge_id'){
            return $this->getExternalId('badges', $val, 'name') ?? false; // Fail if not found
        }

        elseif($type == 'NULL.is_answered'){
            return isset($node['AcceptedAnswerId']) ? 't' : 'f';
        }

        elseif($type == 'NULL.comment.question_id'){
            return $this->getExternalId('questions', $node['PostId']) ?? '\\N';
        } elseif($type == 'NULL.comment.answer_id'){
            return $this->getExternalId('answers', $node['PostId']) ?? '\\N';
        } elseif($type == 'NULL.comment.post_type'){
            return $this->getExternalId('questions', $node['PostId']) !== NULL ? 'question' : 'answer';
        }
        return (string) $val;
    }


    public function processNode($node, $settings){
        $row = [];
        foreach($settings['columns'] as $key => $info){
            list($column, $type) = $info;
            $row[$column] = $this->processType($node[$key] ?? NULL, $type, $node);
            // Disregard a row if a column failed
            if($row[$column] === false){
                return false;
            }
        }

        // Add default columns
        if(in_array('created_at', $settings['defaults']))
            $row['created_at'] = isset($settings['real_date']) ? $row['creation_date'] : date('Y-m-d H:i:s');
        if(in_array('updated_at', $settings['defaults']))
            $row['updated_at'] = isset($settings['real_date']) ? $row['creation_date'] : date('Y-m-d H:i:s');
        if(in_array('site_id', $settings['defaults']))
            $row['site_id'] = $this->config['site_id'];
        return join("\t", array_values($row));
    }


    public function generateHeader($settings){
        $table = $settings['table'];
        $cols = [];
        foreach($settings['columns'] as $v){
            $cols[] = $v[0];
        }

        // Add default columns
        if(in_array('created_at', $settings['defaults']))
            $cols[] = 'created_at';
        if(in_array('updated_at', $settings['defaults']))
            $cols[] = 'updated_at';
        if(in_array('site_id', $settings['defaults']))
            $cols[] = 'site_id';
        $col_string = join(',', $cols);
        return "COPY $table ($col_string) FROM stdin;";
    }


    public function processBasicFile($filename, $settings){
        $streamer = XmlStringStreamer::createStringWalkerParser($this->dataDir . '/' . $filename);
        $output = $settings['output'];

        echo "Processing file $filename...\n";

        $f = fopen($this->dataDir . '/' . $output, 'w');

        fwrite($f,$this->generateHeader($settings) . "\n");

        $i = 0;
        while($node = $streamer->getNode()){
            $xml = simplexml_load_string($node);
            $str = $this->processNode($xml, $settings);
            if($str === false){
                continue;
            }
            fwrite($f, $str . "\n");
            $i++;
        }

        fwrite($f, "\\.\n");
        fclose($f);

        echo "Done. $i rows processed.\n";
    }


    public function processPosts($filename, $settings, $post_type){
        $streamer = XmlStringStreamer::createStringWalkerParser($this->dataDir . '/' . $filename);
        $output = $settings['output'];

        echo "Processing file $filename...\n";

        $f = fopen($this->dataDir . '/' . $output, 'w');
        fwrite($f, $this->generateHeader($settings) . "\n");

        $i = $skip = 0;
        while($node = $streamer->getNode()){
            $xml = simplexml_load_string($node);
            if($xml['PostTypeId'] == $post_type){
                $str = $this->processNode($xml, $settings);
                if($str === false){
                    $skip++;
                    continue;
                }
                fwrite($f, $str . "\n");
                $i++;
                if($i % 1000 == 0){
                    echo "Processed: $i\n";
                }
            }
        }

        fwrite($f, "\\.\n");
        fclose($f);

        echo "Done. $i rows processed, $skip skipped.\n";
    }


    public function processQuestions($filename, $settings){
        $this->processPosts($filename, $settings, '1');
    }


    public function processAnswers($filename, $settings){
        $this->processPosts($filename, $settings, '2');
    }


    public function processPostTags($filename, $settings){
        $streamer = XmlStringStreamer::createStringWalkerParser($this->dataDir . '/' . $filename);
        $output_questions = $settings['output_questions'];
        $output_answers = $settings['output_answers'];

        $qsettings = $settings['questions'];
        $asettings = $settings['answers'];

        echo "Processing file $filename...\n";

        $fq = fopen($this->dataDir . '/' . $output_questions, 'w');
        $fa = fopen($this->dataDir . '/' . $output_answers, 'w');
        fwrite($fq, $this->generateHeader($qsettings) . "\n");
        fwrite($fa, $this->generateHeader($asettings) . "\n");

        $i = $t = $f = 0;
        while($node = $streamer->getNode()){
            $xml = simplexml_load_string($node);

            if(isset($xml['Tags'])){
                $is_question = $xml['PostTypeId'] == '1';

                $tags = $this->extractTags((string) $xml['Tags']);
                $post_id = $this->getExternalId($is_question ? 'questions' : 'answers', $xml['Id']);
                $now = date('Y-m-d H:i:s');
                foreach($tags as $tag){
                    $tag_id = $this->getExternalId('tags', $tag, 'name');
                    if(!$tag_id){
                        $f++;
                        continue;
                    }
                    $row = "$post_id\t$tag_id\t$now\t$now\n";

                    fwrite($is_question ? $fq : $fa, $row);
                    $t++;
                }
            }
            $i++;
        }

        fwrite($fq, "\\.\n");
        fwrite($fa, "\\.\n");
        fclose($fa);
        echo "Done. $i rows processed, $t tags found, $f tags not found.\n";
    }


    public function processAcceptedAnswers($filename, $settings){
        $streamer = XmlStringStreamer::createStringWalkerParser($this->dataDir . '/' . $filename);
        echo "Processing file $filename...\n";
        $f = fopen($this->dataDir . '/' . $settings['output'], 'w');

        $i = 0;
        while($node = $streamer->getNode()){
            $xml = simplexml_load_string($node);

            if($xml['PostTypeId'] != '1' || !isset($xml['AcceptedAnswerId'])){
                continue;
            }
            $answer_id = $this->getExternalId($settings['table_answers'], $xml['AcceptedAnswerId']);
            $question_id = $this->getExternalId($settings['table_questions'], $xml['Id']);
            fwrite($f, trim($this->db->update($settings['table_questions'], ['accepted_answer_id' => $answer_id])->where('id', $question_id)->dump(true)) . ";\n");
            fwrite($f, trim($this->db->update($settings['table_answers'], ['is_accepted' => true])->where('id', $answer_id)->dump(true)) . ";\n");
            $i++;
        }

        fclose($f);
        echo "Done. $i accepted answers processed.\n";
    }


    protected function extractTags($str){
        $tags = explode('><', trim($str, '<>'));
        if(isset($tags[0]) && $tags[0] == ''){
            return [];
        }
        return $tags;
    }


    protected function getExternalId($table, $id, $col = 'external_id'){
        $id = $col == 'external_id' ? (int) $id : (string) $id;
        if(!isset($this->hashTables[$table])){
            echo "  Retrieving $table.$col hash...";
            $this->hashTables[$table] = $this->db->select($table)->where('site_id', $this->config['site_id'])->fetchPairs($col, 'id');
            echo "Done\n";
        }
        return $this->hashTables[$table][$id] ?? NULL;
    }

}



$config = json_decode(file_get_contents($argv[1]), true);

$dbconfig = json_decode(file_get_contents(__DIR__ . '/config.db.json'), true);

$import = new DumpImport($config, __DIR__ . '/data', new Manager($dbconfig));
$import->run($argv[2]);
