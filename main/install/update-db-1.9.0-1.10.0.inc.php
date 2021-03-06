<?php
/* For licensing terms, see /license.txt */

/**
 * Chamilo LMS
 *
 * Update the Chamilo database from an older Chamilo version
 * Notice : This script has to be included by index.php
 * or update_courses.php (deprecated).
 *
 * @package chamilo.install
 * @todo
 * - conditional changing of tables. Currently we execute for example
 * ALTER TABLE $dbNameForm.cours
 * instructions without checking wether this is necessary.
 * - reorganise code into functions
 * @todo use database library
 */
$old_file_version = '1.9.0';
$new_file_version = '1.10.0';

// Check if we come from index.php or update_courses.php - otherwise display error msg
if (defined('SYSTEM_INSTALLATION')) {

    // Check if the current Chamilo install is eligible for update
    if (empty($_configuration)) {
        echo '<strong>'.get_lang('Error').' !</strong> Chamilo '.get_lang('HasNotBeenFound').'.<br /><br />
                                '.get_lang('PleasGoBackToStep1').'.
                                <p><button type="submit" class="back" name="step1" value="&lt; '.get_lang('Back').'">'.get_lang('Back').'</button></p>
                                </td></tr></table></form></body></html>';
        exit();
    }

    $_configuration['db_glue'] = get_config_param('dbGlu');

    if ($singleDbForm) {
        $_configuration['table_prefix'] = get_config_param('courseTablePrefix');
        $_configuration['main_database'] = get_config_param('mainDbName');
        $_configuration['db_prefix'] = get_config_param('dbNamePrefix');
    }

    $dbScormForm = preg_replace('/[^a-zA-Z0-9_\-]/', '', $dbScormForm);

    if (!empty($dbPrefixForm) && strpos($dbScormForm, $dbPrefixForm) !== 0) {
        $dbScormForm = $dbPrefixForm.$dbScormForm;
    }

    if (empty($dbScormForm) || $dbScormForm == 'mysql' || $dbScormForm == $dbPrefixForm) {
        $dbScormForm = $dbPrefixForm.'scorm';
    }

    /*   Normal upgrade procedure: start by updating main, statistic, user databases */

    // If this script has been included by index.php, not update_courses.php, so
    // that we want to change the main databases as well...
    $only_test = false;
    if (defined('SYSTEM_INSTALLATION')) {

        if ($singleDbForm) {
            $dbStatsForm = $dbNameForm;
            $dbScormForm = $dbNameForm;
            $dbUserForm = $dbNameForm;
        }
        /**
         * Update the databases "pre" migration
         */
        include '../lang/english/create_course.inc.php';

        if ($languageForm != 'english') {
            // languageForm has been escaped in index.php
            include '../lang/'.$languageForm.'/create_course.inc.php';
        }

        // Get the main queries list (m_q_list)
        $m_q_list = get_sql_file_contents($new_file_version.'/migrate-db-'.$old_file_version.'-'.$new_file_version.'-pre.sql', 'main');

        if (count($m_q_list) > 0) {
            // Now use the $m_q_list
            /**
             * We connect to the right DB first to make sure we can use the queries
             * without a database name
             */
            if (strlen($dbNameForm) > 40) {
                $app['monolog']->addError('Database name '.$dbNameForm.' is too long, skipping');
            } elseif (!in_array($dbNameForm, $dblist)) {
                $app['monolog']->addError('Database '.$dbNameForm.' was not found, skipping');
            } else {
                iDatabase::select_db($dbNameForm);
                foreach ($m_q_list as $query) {
                    if ($only_test) {
                        $app['monolog']->addInfo("iDatabase::query($dbNameForm,$query)");
                    } else {
                        $res = iDatabase::query($query);
                        if ($res === false) {
                            $app['monolog']->addError('Error in '.$query.': '.iDatabase::error());
                        }
                    }
                }
            }
        }

        if (INSTALL_TYPE_UPDATE == 'update') {
            $session_table = "$dbNameForm.session";
            $session_rel_course_table = "$dbNameForm.session_rel_course";
            $session_rel_course_rel_user_table = "$dbNameForm.session_rel_course_rel_user";
            $course_table = "$dbNameForm.course";

            //Fixes new changes in sessions
            $sql = "SELECT id, date_start, date_end, nb_days_access_before_beginning, nb_days_access_after_end FROM $session_table ";
            $result = iDatabase::query($sql);
            while ($session = Database::fetch_array($result)) {
                $session_id = $session['id'];

                //Fixing date_start
                if (isset($session['date_start']) && !empty($session['date_start']) && $session['date_start'] != '0000-00-00') {
                    $datetime = $session['date_start'].' 00:00:00';
                    $update_sql = "UPDATE $session_table SET display_start_date = '$datetime' , access_start_date = '$datetime' WHERE id = $session_id";
                    iDatabase::query($update_sql);

                    //Fixing nb_days_access_before_beginning
                    if (!empty($session['nb_days_access_before_beginning'])) {
                        $datetime = api_strtotime($datetime, 'UTC') - (86400 * $session['nb_days_access_before_beginning']);
                        $datetime = api_get_utc_datetime($datetime);
                        $update_sql = "UPDATE $session_table SET coach_access_start_date = '$datetime' WHERE id = $session_id";
                        iDatabase::query($update_sql);
                    }
                }

                //Fixing end_date
                if (isset($session['date_end']) && !empty($session['date_end']) && $session['date_end'] != '0000-00-00') {
                    $datetime = $session['date_end'].' 00:00:00';
                    $update_sql = "UPDATE $session_table SET display_end_date = '$datetime', access_end_date = '$datetime' WHERE id = $session_id";
                    iDatabase::query($update_sql);

                    //Fixing nb_days_access_before_beginning
                    if (!empty($session['nb_days_access_after_end'])) {
                        $datetime = api_strtotime($datetime, 'UTC') + (86400 * $session['nb_days_access_after_end']);
                        $datetime = api_get_utc_datetime($datetime);
                        $update_sql = "UPDATE $session_table SET coach_access_end_date = '$datetime' WHERE id = $session_id";
                        iDatabase::query($update_sql);
                    }
                }
            }

            // Fixes new changes session_rel_course
            $sql = "SELECT id_session, sc.course_code, c.id FROM $course_table c INNER JOIN $session_rel_course_table sc ON sc.course_code = c.code";
            $result = iDatabase::query($sql);
            while ($row = Database::fetch_array($result)) {
                $sql = "UPDATE $session_rel_course_table SET c_id = {$row['id']}
                        WHERE course_code = '{$row['course_code']}' AND id_session = {$row['id_session']} ";
                iDatabase::query($sql);
            }

            // Fixes new changes in session_rel_course_rel_user
            $sql = "SELECT id_session, sc.course_code, c.id FROM $course_table c INNER JOIN $session_rel_course_rel_user_table sc ON sc.course_code = c.code";
            $result = iDatabase::query($sql);
            while ($row = Database::fetch_array($result)) {
                $sql = "UPDATE $session_rel_course_rel_user_table SET c_id = {$row['id']}
                        WHERE course_code = '{$row['course_code']}' AND id_session = {$row['id_session']} ";
                iDatabase::query($sql);
            }

            //Updating c_quiz_order
            $teq = "$dbNameForm.c_quiz";
            $seq = "SELECT c_id, session_id, id FROM $teq ORDER BY c_id, session_id, id";
            $req = iDatabase::query($seq);
            $to = "$dbNameForm.c_quiz_order";
            $do = "DELETE FROM $to";
            Database::query($do);
            $cid = 0;
            $temp_session_id = 0;
            $order = 1;
            while ($row = Database::fetch_assoc($req)) {
                if ($row['c_id'] != $cid) {
                    $cid = $row['c_id'];
                    $temp_session_id = $row['session_id'];
                    $order = 1;
                } elseif ($row['session_id'] != $temp_session_id) {
                    $temp_session_id = $row['session_id'];
                    $order = 1;
                }
                $ins = "INSERT INTO $to (c_id, session_id, exercise_id, exercise_order)".
                       " VALUES ($cid, $temp_session_id, {$row['id']}, $order)";
                $rins = iDatabase::query($ins);

                $order++;
            }

            $sql = "SELECT id FROM $dbNameForm.course_field WHERE field_variable = 'special_course'";
            $result = Database::query($sql);
            $fieldData = Database::fetch_array($result, 'ASSOC');
            $id = $fieldData['id'];

            $sql = "INSERT INTO $dbNameForm.course_field_options (field_id, option_value, option_display_text, option_order)
                    VALUES ('$id', '1', '".get_lang('Yes')."', '1')";
            $result = Database::query($sql);

            $sql = "INSERT INTO $dbNameForm.course_field_options (field_id, option_value, option_display_text, option_order)
                    VALUES ('$id', '0', '".get_lang('No')."', '2')";
            Database::query($sql);


            // Moving social group to class
            $output->writeln('Fixing social groups');

            $sql = "SELECT * FROM $dbNameForm.groups";
            $result = Database::query($sql);
            $oldGroups = array();
            if (Database::num_rows($result)) {
                while ($group = Database::fetch_array($result, 'ASSOC')) {

                    $group['name'] = Database::escape_string($group['name']);
                    $group['description'] = Database::escape_string($group['description']);
                    $group['picture'] = Database::escape_string($group['picture_uri']);
                    $group['url'] = Database::escape_string($group['url']);
                    $group['visibility'] = Database::escape_string($group['visibility']);
                    $group['updated_on'] = Database::escape_string($group['updated_on']);
                    $group['created_on'] = Database::escape_string($group['created_on']);

                    $sql = "INSERT INTO $dbNameForm.usergroup (name, group_type, description, picture, url, visibility, updated_on, created_on)
                    VALUES ('{$group['name']}', '1', '{$group['description']}', '{$group['picture_uri']}', '{$group['url']}', '{$group['visibility']}', '{$group['updated_on']}', '{$group['created_on']}')";
                    Database::query($sql);
                    $id = Database::insert_id();
                    $oldGroups[$group['id']] = $id;
                }
            }

            if (!empty($oldGroups)) {
                $output->writeln('Moving group files');
                foreach ($oldGroups as $oldId => $newId) {
                    $path = GroupPortalManager::get_group_picture_path_by_id($oldId, 'system');
                    if (!empty($path)) {
                        var_dump($path['dir']);
                        $newPath = str_replace("groups/$oldId/", "groups/$newId/", $path['dir']);
                        $command = "mv {$path['dir']} $newPath ";
                        system($command);
                        $output->writeln("Moving files: $command");
                    }
                }
                $sql = "SELECT * FROM $dbNameForm.group_rel_user";
                $result = Database::query($sql);

                if (Database::num_rows($result)) {
                    while ($data = Database::fetch_array($result, 'ASSOC')) {
                        if (isset($oldGroups[$data['group_id']])) {
                            $data['group_id'] = $oldGroups[$data['group_id']];
                            $sql = "INSERT INTO $dbNameForm.usergroup_rel_user (usergroup_id, user_id, relation_type)
                            VALUES ('{$data['group_id']}', '{$data['user_id']}', '{$data['relation_type']}')";
                            Database::query($sql);
                        }
                    }
                }

                $sql = "SELECT * FROM $dbNameForm.group_rel_group";
                $result = Database::query($sql);

                if (Database::num_rows($result)) {
                    while ($data = Database::fetch_array($result, 'ASSOC')) {
                        if (isset($oldGroups[$data['group_id']]) && isset($oldGroups[$data['subgroup_id']])) {
                            $data['group_id'] = $oldGroups[$data['group_id']];
                            $data['subgroup_id'] = $oldGroups[$data['subgroup_id']];
                            $sql = "INSERT INTO $dbNameForm.usergroup_rel_usergroup (group_id, subgroup_id, relation_type)
                                    VALUES ('{$data['group_id']}', '{$data['subgroup_id']}', '{$data['relation_type']}')";
                            Database::query($sql);
                        }
                    }
                }

                $sql = "SELECT * FROM $dbNameForm.announcement_rel_group";
                $result = Database::query($sql);

                if (Database::num_rows($result)) {
                    while ($data = Database::fetch_array($result, 'ASSOC')) {
                        if (isset($oldGroups[$data['group_id']])) {
                            //Deleting relation
                            $sql = "DELETE FROM announcement_rel_group WHERE id = {$data['id']}";
                            Database::query($sql);

                            //Add new relation
                            $data['group_id'] = $oldGroups[$data['group_id']];
                            $sql = "INSERT INTO $dbNameForm.announcement_rel_group(group_id, announcement_id)
                            VALUES ('{$data['group_id']}', '{$data['announcement_id']}')";
                            Database::query($sql);
                        }
                    }
                }

                $sql = "SELECT * FROM $dbNameForm.group_rel_tag";
                $result = Database::query($sql);
                if (Database::num_rows($result)) {
                    while ($data = Database::fetch_array($result, 'ASSOC')) {
                        if (isset($oldGroups[$data['group_id']])) {
                            $data['group_id'] = $oldGroups[$data['group_id']];
                            $sql = "INSERT INTO $dbNameForm.usergroup_rel_tag (tag_id, usergroup_id)
                            VALUES ('{$data['tag_id']}', '{$data['group_id']}')";
                            Database::query($sql);
                        }
                    }
                }

                $course = "$dbNameForm.course";
                $sql = "SELECT id FROM $course";
                $result = Database::query($sql);
                $test = false;
                // Getting courses
                while ($data = Database::fetch_array($result, 'ASSOC')) {
                    $courseId = $data['id'];
                    $sql = "SELECT id, iid FROM $dbNameForm.c_quiz WHERE c_id = $courseId";
                    $resultQuiz = Database::query($sql);
                    // getting quiz
                    while ($quiz = Database::fetch_array($resultQuiz, 'ASSOC')) {
                        $quizId = $quiz['id'];
                        $newQuizId = $quiz['iid'];

                        //item properties
                        $sql = "UPDATE $dbNameForm.c_item_property SET ref= $newQuizId WHERE c_id = $courseId AND ref = $quizId AND tool = 'quiz' ";
                        if ($test) {
                            var_dump($sql);
                        } else {
                            Database::query($sql);
                        }

                        //item c_lp_item
                        $sql = "UPDATE $dbNameForm.c_lp_item SET ref= $newQuizId WHERE c_id = $courseId AND ref = $quizId AND item_type = 'quiz' ";
                        if ($test) {
                            var_dump($sql);
                        } else {
                            Database::query($sql);
                        }
                        // getting questions
                        $sql = "SELECT r.question_id, q.iid FROM $dbNameForm.c_quiz_rel_question r INNER JOIN $dbNameForm.c_quiz_question q
                                ON (r.c_id = q.c_id AND r.question_id = q.id)
                                WHERE r.c_id = $courseId AND exercice_id = $quizId";
                        $resultQuestion = Database::query($sql);
                        while ($question = Database::fetch_array($resultQuestion, 'ASSOC')) {

                            $oldQuestionId = $question['question_id'];
                            $newQuestionId = $question['iid'];

                            // moving answers
                            $sql = "SELECT id, iid FROM $dbNameForm.c_quiz_answer WHERE c_id = $courseId AND question_id = $oldQuestionId";
                            $resultAnswer = Database::query($sql);
                            while ($answer = Database::fetch_array($resultAnswer, 'ASSOC')) {
                                $sql = "UPDATE $dbNameForm.c_quiz_answer SET question_id = $newQuestionId
                                        WHERE c_id = $courseId AND question_id = $oldQuestionId";

                                if ($test) {
                                    var_dump($sql);
                                } else {
                                    Database::query($sql);
                                }
                            }

                            $sql = "UPDATE $dbNameForm.c_quiz_rel_question SET exercice_id = $newQuizId, question_id = $newQuestionId
                                    WHERE c_id = $courseId AND question_id = $oldQuestionId";
                            if ($test ) {
                                var_dump($sql);
                            } else {
                                Database::query($sql);
                            }
                        }

                        // Categories
                        $sql = "SELECT iid, id FROM $dbNameForm.c_quiz_category WHERE c_id = $courseId";
                        $resultCat = Database::query($sql);
                        while ($category = Database::fetch_array($resultCat, 'ASSOC')) {
                            $oldId = $category['id'];
                            $newId = $category['iid'];

                            $sql = "UPDATE $dbNameForm.c_quiz_rel_category SET exercice_id = $newQuizId, category_id = $newId
                                    WHERE c_id = $courseId AND category_id = $oldId";
                            if ($test ) {
                                var_dump($sql);
                            } else {
                                Database::query($sql);
                            }
                        }
                    }
                }
            }
        }
    }
}
