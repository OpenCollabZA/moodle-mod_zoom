<?php
// This file is part of the Zoom plugin for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Task: send_ical_notification
 *
 * @package    mod_zoom
 * @copyright  2024 OPENCOLLAB <info@opencollab.co.za>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/calendar/lib.php');
require_once($CFG->libdir.'/bennu/bennu.inc.php');
require_once($CFG->libdir.'/bennu/iCalendar_components.php');
require_once($CFG->dirroot . '/mod/zoom/locallib.php');

/**
 * Scheduled task to send ical notifications for zoom meetings that were scheduled within the last 30 minutes.
 */
class send_ical_notifications extends \core\task\scheduled_task {

    /**
     * Execute the send ical notifications cron function.
     *
     * @return void nothing.
     */
    public function execute() {
        if (get_config('zoom', 'sendicalnotifications')) {
            mtrace('[Zoom ical Notifications] Starting cron job.');
            $zoomevents = $this->get_zoom_events_to_notify();
            if ($zoomevents) {
                foreach ($zoomevents as $zoomevent) {
                    mtrace('[Zoom ical Notifications] Checking to see if a zoom event with with ID ' .
                            $zoomevent->id . ' was notified before.');
                    $executiontime = $this->get_notification_executiontime($zoomevent->id);
                    // Only run if it hasn't run before.
                    if ($executiontime == 0) {
                        mtrace('[Zoom ical Notifications] Zoom event with ID ' .
                                $zoomevent->id . ' can be notified - not notified before.');
                        $this->send_zoom_ical_notifications($zoomevent);
                        // Set execution time for this cron job.
                        mtrace('[Zoom ical Notifications] Zoom event with ID ' . $zoomevent->id .
                                ' was successfully notified - set execution time for log table.');
                        $this->set_notification_executiontime($zoomevent->id);
                    }
                }
            } else {
                mtrace('[Zoom ical Notifications] Found no zoom event records to process and notify ' .
                       '(created or modified within the last hour that was not notified before).');
            }
            mtrace('[Zoom ical Notifications] Cron job Completed.');
        } else {
            mtrace('[Zoom ical Notifications] The Admin Setting for the Send iCal Notification scheduled task ' .
                   'has not been enabled - will not run the cron job.');
        }
    }

    /**
     * Get zoom events created/modified in the last hour, but ignore the last 10 minutes. 
     * This allows the user to still make adjustments to the event before the ical invite is sent out.
     * @return array
     */
    private function get_zoom_events_to_notify() {
        global $DB;

        $sql = 'SELECT *
        FROM {event}
        WHERE modulename = :zoommodulename
        AND eventtype = :zoomeventtype
        AND timemodified >= (unix_timestamp() - (60 * 60))
        AND timemodified <= (unix_timestamp() - (10 * 60))';

        return $DB->get_records_sql($sql, ['zoommodulename' => 'zoom', 'zoomeventtype' => 'zoom']);
    }

    /**
     * Get the execution time (last successful ical notifications sent) for the related zoom event id.
     * @param string $zoomeventid The zoom event id.
     * @return string The timestamp of the last execution.
     */
    private function get_notification_executiontime(string $zoomeventid) {
        global $DB;

        $executiontime = $DB->get_field('zoom_ical_notifications', 'executiontime', ['zoomeventid' => $zoomeventid]);
        if (!$executiontime) {
            $executiontime = 0;
        }
        return $executiontime;
    }

    /**
     * Set the execution time (the current time) for successful ical notifications sent for the related zoom event id.
     * @param string $zoomeventid The zoom event id.
     */
    private function set_notification_executiontime(string $zoomeventid) {
        global $DB;

        $icalnotifojb = new \stdClass();
        $icalnotifojb->zoomeventid = $zoomeventid;
        $icalnotifojb->executiontime = time();

        $DB->insert_record('zoom_ical_notifications', $icalnotifojb);
    }

    /**
     * The zoom ical notification task.
     * @param stdClass $zoomevent The zoom event record.
     */
    private function send_zoom_ical_notifications($zoomevent) {
        global $DB;

        mtrace('[Zoom ical Notifications] Notifying Zoom event with ID ' . $zoomevent->id);

        $users = $this->get_users_to_notify($zoomevent->instance, $zoomevent->courseid);

        $zoom = $DB->get_record('zoom', ['id' => $zoomevent->instance], 'id,registration,join_url,meeting_id,webinar');

        $filestorage = get_file_storage();

        foreach ($users as $user) {
            // Check if user has "Disable notifications" set.
            if ($user->emailstop) {
                continue;
            }

            $ical = $this->create_ical_object($zoomevent, $zoom, $user);

            $filerecord = [
                'contextid' => \context_user::instance($user->id)->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => file_get_unused_draft_itemid(),
                'filepath' => '/',
                'filename' => clean_filename('icalexport.ics'),
            ];

            $serializedical = $ical->serialize();
            if (!$serializedical || empty($serializedical)) {
                mtrace('[Zoom ical Notifications] A problem occurred while trying to serialize the ical data for user ID ' .
                        $user->id . ' for zoom event ID ' . $zoomevent->id);
                continue;
            }

            $icalfileattachment = $filestorage->create_file_from_string($filerecord, $serializedical);

            $messagedata = new \core\message\message();
            $messagedata->component = 'mod_zoom';
            $messagedata->name = 'ical_notifications';
            $messagedata->userfrom = \core_user::get_noreply_user();
            $messagedata->userto = $user;
            $messagedata->subject = $zoomevent->name;
            $messagedata->fullmessage = $zoomevent->description;
            $messagedata->fullmessageformat = FORMAT_HTML;
            $messagedata->fullmessagehtml = $zoomevent->description;
            $messagedata->smallmessage = $zoomevent->name . ' - ' . $zoomevent->description;
            $messagedata->notification = true;
            $messagedata->attachment = $icalfileattachment;
            $messagedata->attachname = $icalfileattachment->get_filename();

            $emailsuccess = message_send($messagedata);

            if ($emailsuccess) {
                mtrace('[Zoom ical Notifications] Successfully emailed user ID ' . $user->id .
                        ' for zoom event ID ' . $zoomevent->id);
            } else {
                mtrace('[Zoom ical Notifications] A problem occurred while emailing user ID ' . $user->id .
                        ' for zoom event ID ' . $zoomevent->id);
            }
        }
    }

    /**
     * Create the ical object.
     * @param stdClass $zoomevent The zoom event record.
     * @param stdClass $zoom The zoom record.
     * @param stdClass $user The user object.
     * @return \iCalendar
     */
    private function create_ical_object($zoomevent, $zoom, $user) {
        global $CFG, $SITE;

        $ical = new \iCalendar();
        $ical->add_property('method', 'PUBLISH');
        $ical->add_property('prodid', '-//Moodle Pty Ltd//NONSGML Moodle Version ' . $CFG->version . '//EN');

        $icalevent = zoom_helper_icalendar_event($zoomevent, $zoomevent->description);

        if ($zoom->registration == ZOOM_REGISTRATION_OFF) {
            $icalevent->add_property('location', $zoom->join_url);
        } else {
            $registrantjoinurl = zoom_get_registrant_join_url($user->email, $zoom->meeting_id, $zoom->webinar);
            if ($registrantjoinurl) {
                $icalevent->add_property('location', $registrantjoinurl);
            } else {
                $icalevent->add_property('location', $zoom->join_url);
            }
        }

        $noreplyuser = \core_user::get_noreply_user();
        $icalevent->add_property('organizer', 'mailto:' . $noreplyuser->email, ['cn' => $SITE->fullname]);
        // Need to strip out the double quotations around the 'organizer' values - probably a bug in the core code.
        $icalevent->properties['ORGANIZER'][0]->value = substr($icalevent->properties['ORGANIZER'][0]->value, 1, -1);
        $icalevent->properties['ORGANIZER'][0]->parameters['CN'] = substr($icalevent->properties['ORGANIZER'][0]->parameters['CN'], 1, -1);

        // Add the event to the iCal file.
        $ical->add_component($icalevent);

        return $ical;
    }

    /**
     * Get an array of users in the format of userid=>user object.
     * @param string $zoomid The zoom instance id.
     * @param string $courseid The course id of the course in which the zoom event occurred.
     * @return array An array of users.
     */
    private function get_users_to_notify($zoomid, $courseid) {
        global $DB;
        $users = [];

        $sql = 'SELECT distinct ue.userid
        FROM {zoom} z
        JOIN {enrol} e
        ON e.courseid = z.course
        JOIN {user_enrolments} ue
        ON ue.enrolid = e.id
        WHERE z.id = :zoom_id';

        $zoomparticipantsids = $DB->get_records_sql($sql, ['zoom_id' => $zoomid]);
        if ($zoomparticipantsids) {
            foreach ($zoomparticipantsids as $zoomparticipantid) {
                $users += [$zoomparticipantid->userid => \core_user::get_user($zoomparticipantid->userid)];
            }
        }

        if (count($users) > 0) {
            $users = $this->filter_users($zoomid, $courseid, $users);
        }

        return $users;
    }

    /**
     * Filter the zoom users based on availability restrictions.
     * @param stdClass $zoom The zoom entry.
     * @param array $users An array of users that potentially has access to the Zoom activity.
     * @return array A filtered array of users.
     */
    private function filter_users($zoomid, $courseid, $users) {
        $modinfo = get_fast_modinfo($courseid);
        $coursemodules = $modinfo->get_cms();
        if ($coursemodules) {
            foreach ($coursemodules as $coursemod) {
                if ($coursemod->modname == 'zoom' && $coursemod->instance == $zoomid) {
                    $availinfo = new \core_availability\info_module($coursemod);
                    $users = $availinfo->filter_user_list($users);
                    break;
                }
            }
        }
        return $users;
    }

    /**
     * Returns the name of the task.
     *
     * @return string task name.
     */
    public function get_name() {
        return get_string('sendicalnotifications', 'mod_zoom');
    }

}
