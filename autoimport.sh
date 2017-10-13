#!/usr/bin/env bash

CONFIG='config.json'
POSTGRES_USER='postgres'
POSTGRES_DBNAME='stackletter_dev'

GENERATE="php import.php $CONFIG"
IMPORT="sudo -u $POSTGRES_USER psql -d $POSTGRES_DBNAME"

$GENERATE users;
$IMPORT < data/users.sql

$GENERATE questions;
$IMPORT < data/questions.sql

$GENERATE answers;
$IMPORT < data/answers.sql

$GENERATE comments;
$IMPORT < data/comments.sql

$GENERATE tags;
$IMPORT < data/question_tags.sql
$IMPORT < data/answer_tags.sql

$GENERATE badges;
$IMPORT < data/user_badges.sql
