<?php
/**
 * Created by PhpStorm.
 * User: koell
 * Date: 21 Apr 2018
 * Time: 10:03 PM
 *
 * Dead man switch
 */

require 'info.php'; //this contains some database and personal info.

define('PHASE_DAYS', 28);

$db = mysqli_connect('localhost', DB_USR, DB_PW, DB_NAME);

function time_passed(){

    global $db;

    if ($db->connect_errno) {
        echo $db->connect_error;
        return;
    }

    $lastDeadStatus = getLastDeadStatus();

    $now = new DateTime();
    $now->setTimezone(new DateTimeZone('+0000')); //my host has some timezone issues

    echo '<p>Current time: ' . $now->format('c') . '</p>';

    $time_diff = $now->diff($lastDeadStatus->activation_datetime, true);

    if ($lastDeadStatus->phase == 4){
        echo '<p>PHASE 4 ALREADY TRIGGERED</p>';
        echo '<p>Deadman messages sent to all recipients at ' . $lastDeadStatus->activation_datetime->format('c') . '</p>';
        echo '<p>Note that this cannot be undone or reset</p>';

    }else if ($time_diff->days >= PHASE_DAYS) {

        if ($lastDeadStatus->phase <= 1) {
            $new_phase = $lastDeadStatus->phase+1;
            $reset_code = update_phase($new_phase);

            $email_body = gen_reset_email($new_phase, $now->format('c'), $reset_code);
            $email_subject = '[ACTION REQUIRED] Dead man switch triggered';
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= 'From: '.EMAIL_FROM . "\r\n";

            //send single reset email
            mail(PERSONAL_EMAIL, $email_subject, $email_body, $headers);

            echo '<p>PHASE '. $new_phase . '</p>';
            echo '<p>Deadman postponement email sent</p>';
            echo '<p>reset code is: ' . $reset_code . '</p>';
            echo '<p>last activation was at ' . $lastDeadStatus->activation_datetime->format('c') . '</p><br/>';

        } else if ($lastDeadStatus->phase == 2) {
            $reset_code = update_phase(3);

            $email_body = gen_reset_email(3, $now->format('c'), $reset_code);
            $email_subject = '[ACTION REQUIRED] Dead man switch triggered';
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= 'From: '.EMAIL_FROM . "\r\n";

            $alt_emails = unserialize(ALT_EMAILS);

            //send to all alt accounts
            for ($i = 0; $i < sizeof($alt_emails); $i++) {

                mail($alt_emails[$i], $email_subject, $email_body, $headers);

            }

            echo '<p>PHASE 3</p>';
            echo '<p>Deadman postponement email sent to alt emails</p>';
            echo '<p>reset code is: ' . $reset_code . '</p>';
            echo '<p>last activation was at ' . $lastDeadStatus->activation_datetime->format('c') . '</p><br/>';

        }else if ($lastDeadStatus->phase == 3) {
            update_phase(4);

            $email_subject = 'Jans George Rautenbach dead man switch triggered';
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= 'From: '.EMAIL_FROM . "\r\n";

            //send all the messages
            $deadMessages = $db->query('SELECT recipient, message, UNIX_TIMESTAMP(last_updated) AS written_tstamp FROM deadmessages ORDER BY last_updated DESC');
            while (($row = $deadMessages->fetch_assoc())) {

                $dt = new DateTime();
                $dt->setTimestamp($row['written_tstamp']);
                $dt->setTimezone(new DateTimeZone('+0000'));

                $email_body = gen_deadmessage($row['message'], $dt->format('c'));

                mail($row['recipient'], $email_subject, $email_body, $headers);

            }

            $db->query('DELETE FROM deadmessages'); //delete all local copies of the messages after sending, for dramatic effect

            echo '<p>PHASE 4</p>';
            echo '<p>Deadman messages sent to all recipients.</p>';
            echo '<p>Note that this cannot be undone or reset</p>';

        }else{ //phase 4 already handled up top
            echo 'phase number not recognised: ' . $lastDeadStatus->phase;
        }


    } else {
        echo '<p>NO TRIGGER</p>';
        echo '<p>' . (PHASE_DAYS - $time_diff->days) . ' more days need to pass for the next phase';
        echo '<p>the system is currently on phase ' . $lastDeadStatus->phase . '</p>';
        echo '<p>last activation was at ' . $lastDeadStatus->activation_datetime->format('c') . '</p><br/>';
    }

}

function gen_deadmessage($personal_msg, $written_date){

    $id = PERSONAL_ID;

    return "
<html>
<head>
    <title>Dead man switch trigggered</title>
</head>
<body>
    <p>Good day</p>
    <p>Jans George Rautenbach (ID: $id) has set up a dead man switch to automatically send a set of messages to a specified list of recipients in the event of his death. You have been marked as one of these recipients. Your personal message follows:</p>
    <p>---</p>
    <p>$personal_msg</p>
    <p>---</p>
    <p>This message was written on: $written_date</p>
    <p>Note that this event has been triggered because of 4 months of inactivity. This event will never trigger again and cannot be reset. Every message is automatically deleted from the sending server after it is sent. This email contains the only copy of your personal message in existence.</p>
</body>
</html>
";

}

function gen_reset_email($phase, $trigger_date, $reset_code){

    $reset_url = DEADMAN_URL . "?action=reset&reset_code=$reset_code";

    return "<html>".
        "<head><title>Dead man switch triggered</title></head><body>".
        "<p>Your dead man switch system is currently on <strong>phase $phase</strong>.</p>".
        "<p>The trigger happened on $trigger_date</p>".
        "<p>Click on the following link to reset it:</p>".
        "<p><a href='$reset_url'><strong>RESET DEAD MAN SWITCH</strong></a></p>".
        "</body></html>";


}

function update_phase($phase){

    global $db;

    $reset_code = password_hash((new DateTime())->getTimestamp() * $phase, PASSWORD_BCRYPT);

    $stmt = $db->prepare('INSERT INTO deadstatuses(phase, reset_code) VALUES (?, ?)');
    $stmt->bind_param('is', $phase, $reset_code);
    $stmt->execute();

    $stmt->close();

    return $reset_code;

}

function reset_phase($reset_code){

    $lastDeadStatus = getLastDeadStatus();

    if ($lastDeadStatus->phase == 4){
        echo '<p>PHASE 4 ALREADY TRIGGERED</p>';
        echo '<p>Deadman messages sent to all recipients at ' . $lastDeadStatus->activation_datetime->format('c') . '</p>';
        echo '<p>Note that this cannot be undone or reset</p>';
        return;
    }

    $correct_code = $lastDeadStatus->reset_code;

    if ($reset_code == $correct_code){
        update_phase(0);

        echo '<p>PHASE RESET TO 0</p>';
        echo '<p>last activation was at ' . $lastDeadStatus->activation_datetime->format('c') . '</p><br/>';

    }else{

        $time_diff = (new DateTime())->diff($lastDeadStatus->activation_datetime, true);

        echo '<p>INCORRECT RESET CODE</p>';
        echo '<p>the system is currently on phase '. $lastDeadStatus->phase .'</p>';
        echo '<p>the next phase will trigger in '.(PHASE_DAYS - $time_diff->s). ' seconds</p>';
        echo '<p>last activation was at ' .$lastDeadStatus->activation_datetime->format('c') . '</p><br/>';

    }

}

/**
 * gets the last deadman status
 *
 * @return DeadStatus. if no prior deadman statuses, the default DeadStatus obj is returned.
 *
 */
function getLastDeadStatus(){

    global $db;

    $deadStatuses = $db->query('SELECT phase, UNIX_TIMESTAMP(activation_timestamp) AS act_tstamp, reset_code FROM deadstatuses ORDER BY activation_timestamp DESC');
    $newest_deadStatus = $deadStatuses->fetch_assoc();

    if ($newest_deadStatus != null) {
        $lastDeadStatus = new DeadStatus();
        $dt = new DateTime();
        $dt->setTimestamp($newest_deadStatus['act_tstamp']);
        $dt->setTimezone(new DateTimeZone('+0000'));
        $lastDeadStatus->activation_datetime = $dt;
        $lastDeadStatus->phase = $newest_deadStatus['phase'];
        $lastDeadStatus->reset_code = $newest_deadStatus['reset_code'];

        return $lastDeadStatus;

    }else{
        update_phase(0);
        return getLastDeadStatus();
    }

}

class DeadStatus{
    public $activation_datetime;
    public $phase;
    public $reset_code;

    public function __construct(){
        $this->activation_datetime = new DateTime();
        $this->phase = 0;
        $this->reset_code = '';
    }
}


if ($_GET['action'] == 'time_passed') {
    time_passed();
} else if ($_GET['action'] == 'reset') {
    reset_phase($_GET['reset_code']);
} else {
    echo 'invalid action set in GET';
}

$db->close();