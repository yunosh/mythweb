<?php
/***                                                                        ***\
    recording_schedules.php                  Last Updated: 2005.02.03 (xris)

    The Recording object, and a couple of related subroutines.
\***                                                                        ***/

// Include our dependencies -- these are probably called elsewhere, but require_once will handle it
    require_once 'includes/mythbackend.php';
    require_once 'includes/channels.php';
    require_once 'includes/programs.php';
    require_once 'includes/css.php';

// Constants for the recording types
    define('rectype_once',        1);
    define('rectype_daily',       2);
    define('rectype_channel',     3);
    define('rectype_always',      4);
    define('rectype_weekly',      5);
    define('rectype_findone',     6);
    define('rectype_override',    7);
    define('rectype_dontrec',     8);
    define('rectype_finddaily',   9);
    define('rectype_findweekly', 10);

// Recording types -- enum at the top of libs/libmythtv/recordingtypes.h
    $RecTypes = array(
                      rectype_once       => t('rectype: once'),
                      rectype_daily      => t('rectype: daily'),
                      rectype_channel    => t('rectype: channel'),
                      rectype_always     => t('rectype: always'),
                      rectype_weekly     => t('rectype: weekly'),
                      rectype_findone    => t('rectype: findone'),
                      rectype_override   => t('rectype: override'),
                      rectype_dontrec    => t('rectype: dontrec'),
                      rectype_finddaily  => t('rectype: finddaily'),
                      rectype_findweekly => t('rectype: findweekly'),
                     );

// Global lists of recording schedules and scheduled recordings
    global $Schedules;
    $Schedules = array();
// Build the sql query, and execute it
    $query = 'SELECT *, IF(type='.rectype_always.',-1,chanid) as chanid,'
            .' UNIX_TIMESTAMP(startdate)+TIME_TO_SEC(starttime) AS starttime,'
            .' UNIX_TIMESTAMP(enddate)+TIME_TO_SEC(endtime) AS endtime'
            .' FROM record ';
    $result = mysql_query($query)
        or trigger_error('SQL Error: '.mysql_error(), FATAL);
// Load in all of the recordings (if any?)
    while ($row = mysql_fetch_assoc($result)) {
        $Schedules[$row['recordid']] =& new Schedule($row);
    }
// Cleanup
    mysql_free_result($result);

// Load all of the scheduled recordings.  We will need them at some point, so we
// might as well get it overwith here.
    global $Scheduled_Recordings, $Num_Conflicts, $Num_Scheduled;
    $Scheduled_Recordings = array();
    foreach (get_backend_rows('QUERY_GETALLPENDING', 2) as $key => $program) {
    // The offset entry
        if ($key === 'offset') {
            list($Num_Conflicts, $Num_Scheduled) = $program;
        }
    // Normal entry:  $Scheduled_Recordings[chanid][starttime]
        else {
            $Scheduled_Recordings[$program[4]][$program[11]] =& new Program($program);
        }
    }

//
//  Recording Schedule class
//
class Schedule {

    var $recordid;
    var $type;
    var $chanid;
    var $starttime;
    var $endtime;
    var $title;
    var $subtitle;
    var $description;
    var $profile;
    var $recpriority;
    var $category;
    var $maxnewest;
    var $maxepisodes;
    var $autoexpire;
    var $startoffset;
    var $endoffset;
    var $recgroup;
    var $dupmethod;
    var $dupin;
    var $station;
    var $seriesid;
    var $programid;
    var $search;
    var $autotranscode;
    var $autocommflag;
    var $autouserjob1;
    var $autouserjob2;
    var $autouserjob3;
    var $autouserjob4;
    var $findday;
    var $findtime;
    var $findid;

    var $texttype;
    var $channel;
    var $will_record = false;
    var $class;         // css class, based on category and/or category_type

    function Schedule($data) {
    // Schedule object data -- just copy it into place
        if (is_object($data)) {
        // Not the right type of object?
            if (get_class($data) != 'schedule')
                trigger_error("Incorrect object of class ".get_class($data)." passed to new Schedule()", FATAL);
        // Copy its variables into place
            $a = @get_object_vars($data);
            if (is_array($a) && count($a) > 0) {
                foreach ($a as $key => $val) {
                    $this->$key = $val;
                }
            }
        }
    // Empty Schedule
        elseif (is_null($data)) {
            return;
        }
    // Something else
        else {
        // Data is a recordid -- load its contents
            if (!is_array($data) && $data > 0) {
                $query = 'SELECT *, IF(type='.rectype_always.',-1,chanid) as chanid,'
                        .' UNIX_TIMESTAMP(startdate)+TIME_TO_SEC(starttime) AS starttime,'
                        .' UNIX_TIMESTAMP(enddate)+TIME_TO_SEC(endtime) AS endtime'
                        .' FROM record WHERE recordid='.escape($data);
                $result = mysql_query($query)
                    or trigger_error('SQL Error: '.mysql_error(), FATAL);
                $data = mysql_fetch_assoc($result);
                mysql_free_result($result);
            }
        // Array?
            if (is_array($data) && isset($data['recordid'])) {
                foreach ($data as $key => $val) {
                    $this->$key = $val;
                }
            }
        }

    // Add a generic "will record" variable, too
        $this->will_record = ($this->type && $this->type != rectype_dontrec) ? true : false;

    // Turn type int a word
        $this->texttype = $GLOBALS['RecTypes'][$this->type];
    // Do we have a chanid?  Load some info about it
        if ($this->chanid && !isset($this->channel)) {
        // No channel data?  Load it
            global $Channels;
            if (!is_array($Channels) || !count($Channels))
                load_all_channels($this->chanid);
        // Now we really should scan the $Channel array and add a link to this recording's channel
            foreach (array_keys($Channels) as $key) {
                if ($Channels[$key]->chanid == $this->chanid) {
                    $this->channel = &$Channels[$key];
                    break;
                }
            }
        }

    // Find out which css category this recording falls into
        if ($this->chanid != '')
            $this->class = category_class($this);
    }

/*
    save:
    save this schedule
*/
    function save($new_type) {
    // Make sure that recordid is null if it's empty
        if (empty($this->recordid))
            $this->recordid = NULL;
    // Changing the type of recording
        if ($this->recordid && $this->type && $new_type != $this->type) {
        // Delete this schedule?
            if (empty($new_type)) {
                $this->delete();
                return;
            }
        // Changing from one override type to another -- delete the old entry, and then reset recordid so a new record is created
            elseif ($new_type == rectype_override || $new_type == rectype_dontrec) {
            // Delete an old override schedule?
                if ($this->type == rectype_override || $this->type == rectype_dontrec) {
                    $this->delete();
                }
            // Wipe the recordid so we actually create a new record
                $this->recordid = NULL;
            }
        }
    // Update the type, in case it changed
        $this->type = $new_type;
    // Update the record
        $result = mysql_query('REPLACE INTO record (recordid,type,chanid,starttime,startdate,endtime,enddate,title,subtitle,description,profile,recpriority,category,maxnewest,maxepisodes,autoexpire,startoffset,endoffset,recgroup,dupmethod,dupin,station,seriesid,programid,autocommflag) values ('
                                .escape($this->recordid, true)             .','
                                .escape($this->type)                       .','
                                .escape($this->chanid)                     .','
                                .'FROM_UNIXTIME('.escape($this->starttime).'),'
                                .'FROM_UNIXTIME('.escape($this->starttime).'),'
                                .'FROM_UNIXTIME('.escape($this->endtime)  .'),'
                                .'FROM_UNIXTIME('.escape($this->endtime)  .'),'
                                .escape($this->title)                      .','
                                .escape($this->subtitle)                   .','
                                .escape($this->description)                .','
                                .escape($this->profile)                    .','
                                .escape($this->recpriority)                .','
                                .escape($this->category)                   .','
                                .escape($this->maxnewest)                  .','
                                .escape($this->maxepisodes)                .','
                                .escape($this->autoexpire)                 .','
                                .escape($this->startoffset)                .','
                                .escape($this->endoffset)                  .','
                                .escape($this->recgroup)                   .','
                                .escape($this->dupmethod)                  .','
                                .escape($this->dupin)                      .','
                                .escape($this->station)                    .',' // callsign!
                                .escape($this->seriesid)                   .','
                                .escape($this->programid)                  .','
                                .escape($this->autocommflag)               .')')
            or trigger_error('SQL Error: '.mysql_error(), FATAL);
    // Get the id that was returned
        $recordid = mysql_insert_id();
    // New recordid?
        if (empty($this->recordid))
            $this->recordid = $recordid;
    // Errors?
        if (mysql_affected_rows() < 1 || $recordid < 1)
            trigger_error('Error creating recording schedule - no id was returned', FATAL);
        elseif ($program->recordid && $program->recordid != $recordid)
            trigger_error('Error updating recording schedule - different id was returned', FATAL);
    // Notify the backend of the changes
        backend_notify_changes($this->recordid);
    }

/*
    delete:
    Delete this schedule
*/
    function delete() {
    // Delete this schedule from the database
        $result = mysql_query('DELETE FROM record WHERE recordid='.escape($this->recordid))
            or trigger_error('SQL Error: '.mysql_error().' [#'.mysql_errno().']', FATAL);
    // Notify the backend of the changes
        if (mysql_affected_rows())
            backend_notify_changes($this->recordid);
    // Remove this from the $Schedules array in memory
        unset($GLOBALS['Schedules'][$this->recordid]);
    }

/*
    details_table:
    The "details table" for recording schedules.  Very similar to that for
    programs, but with a few extra checks, and some information arranged
    differently.
*/
    function details_table() {
    // Start the table, and print the show title
        $str = "<table border=\"0\" cellpadding=\"2\" cellspacing=\"0\">\n<tr>\n\t<td align=\"right\">"
              .t('Title')
              .":</td>\n\t<td>"
              .$this->title
              ."</td>\n</tr>";
    // Type
        $str .= "<tr>\n\t<td align=\"right\">"
               .t('Type')
               .":</td>\n\t<td>"
               .$this->texttype
               ."</td>\n</tr>";
    // Only show these fields for recording types where they're relevant
        if (in_array($this->type, array(rectype_once, rectype_daily, rectype_weekly, rectype_override, rectype_dontrec))) {
        // Airtime
            $str .= "<tr>\n\t<td align=\"right\">"
                   .t('Airtime')
                   .":</td>\n\t<td>"
                   .strftime($_SESSION['date_scheduled_popup'].', '.$_SESSION['time_format'], $this->starttime)
                   .' to '.strftime($_SESSION['time_format'], $this->endtime)
                   ."</td>\n</tr>";
        // Subtitle
            if (preg_match('/\\S/', $this->subtitle)) {
                $str .= "<tr>\n\t<td align=\"right\">"
                       .t('Subtitle')
                       .":</td>\n\t<td>"
                       .$this->subtitle
                       ."</td>\n</tr>";
            }
        // Description
            if (preg_match('/\\S/', $this->description)) {
                $str .= "<tr>\n\t<td align=\"right\" valign=\"top\">"
                       .t('Description')
                       .":</td>\n\t<td>"
                       .nl2br(wordwrap($this->description, 70))
                       ."</td>\n</tr>";
            }
        // Rating
            if (preg_match('/\\S/', $this->rating)) {
                $str .= "<tr>\n\t<td align=\"right\">"
                       .t('Rating')
                       .":</td>\n\t<td>"
                       .$this->rating
                       ."</td>\n</tr>";
            }
        }
    // Category
        if (preg_match('/\\S/', $this->category)) {
            $str .= "<tr>\n\t<td align=\"right\">"
                   .t('Category')
                   .":</td>\n\t<td>"
                   .$this->category
                   ."</td>\n</tr>";
        }
    // Rerun?
        if (!empty($this->previouslyshown)) {
            $str .= "<tr>\n\t<td align=\"right\">"
                   .t('Rerun')
                   .":</td>\n\t<td>"
                   .t('Yes')
                   ."</td>\n</tr>";
        }
    // Will be recorded at some point in the future?
        if (!empty($this->will_record)) {
            $str .= "<tr>\n\t<td align=\"right\">"
                   .t('Schedule')
                   .":</td>\n\t<td>";
            switch ($this->type) {
                case rectype_once:       $str .= t('rectype-long: once');       break;
                case rectype_daily:      $str .= t('rectype-long: daily');      break;
                case rectype_channel:    $str .= t('rectype-long: channel');    break;
                case rectype_always:     $str .= t('rectype-long: always');     break;
                case rectype_weekly:     $str .= t('rectype-long: weekly');     break;
                case rectype_findone:    $str .= t('rectype-long: findone');    break;
                case rectype_override:   $str .= t('rectype-long: override');   break;
                case rectype_dontrec:    $str .= t('rectype-long: dontrec');    break;
                case rectype_finddaily:  $str .= t('rectype-long: finddaily');  break;
                case rectype_findweekly: $str .= t('rectype-long: findweekly'); break;
                default:                 $str .= t('Unknown');
            }
            $str .= "</td>\n</tr>";
        }
    // Which duplicate-checking method will be used
        if ($this->dupmethod > 0) {
            $str .= "<tr>\n\t<td align=\"right\">"
                   .t('Dup Method')
                   .":</td>\n\t<td>";
            switch ($this->dupmethod) {
                case 1:  $str .= t('None');                         break;
                case 2:  $str .= t('Subtitle');                     break;
                case 4:  $str .= t('Description');                  break;
                case 6:  $str .= t('Subtitle and Description');     break;
                case 22: $str .= t('Sub and Desc (Empty matches)'); break;
            }
            $str .= "</td>\n</tr>";
        }
    // Profile
        if (preg_match('/\\S/', $this->profile)) {
            $str .= "<tr>\n\t<td align=\"right\">"
                   .t('Profile')
                   .":</td>\n\t<td>"
                   .$this->profile
                   ."</td>\n</tr>";
        }
    // Recording Group
        if (!empty($this->recgroup)) {
            $str .="<tr>\n\t<td align=\"right\">"
                   .t('Recording Group')
                   .":</td>\n\t<td>"
                   .$this->recgroup
                   ."</td>\n</tr>";
        }
    // Finish off the table and return
        $str .= "\n</table>";
        return $str;
    }

}

?>
