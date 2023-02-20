<?php
// S Ashby, 23/01/23, created
// Charge controller for OpenEVSE car charger to utilize excxess solar PV output
// 1.1 check for current in 'off' state and re-assert override as unnit seems to default to 'on' when left alone/restarting/reconencting/whatever!

echo "OpenEVSECtrl started\n";

require "../phpMQTT-master/phpMQTT.php";

$server = "localhost";     // change if necessary
$port = 1883;                     // change if necessary
$username = "";                   // set your username
$password = "";                   // set your password
$client_id = "openEVSECtrl-subscriber"; // make sure this is unique for connecting to sever - you could use uniqid()

// config
$start_threshold = 6*240; //watts
$min_charge = 300; //sec
$pilot_max = 15; // unit is on 13A plug :)
$pilot_min = 8; // Leaf stops charging below this point
$domo_idx_evpwr = 3515; // Domoticz IDX for charge meter
$domo_idx_ctrl = 3559; // Domoticz control input for this script - level 0,10,20,30 == off, excess only, min power, max power
// averaging params
$pct_add = 0.4;
$pct_sub = 1-$pct_add;
// topics
$gridpower_topic = "geotogether-domo/gridpower";
$openevse_base_topic = "openevse";
$gridvolts_topic = $openevse_base_topic."/voltage";
$openevse_state_topic = $openevse_base_topic."/state";
$openevse_override_set = $openevse_base_topic."/override/set";
$openevse_amps_topic = $openevse_base_topic."/amp";

// debug
$debug=false;

// include Syslog class for remote syslog feature
require "../Syslog-master/Syslog.php";
Syslog::$hostname = "localhost";
Syslog::$facility = LOG_DAEMON;
Syslog::$hostToLog = "rtl433-domo";

function report($msg, $level = LOG_INFO, $cmp = "openevsectrl") {
	global $debug;
	if($debug) echo "openevsectrl:".$level.":".$msg."\n";
    Syslog::send($msg, $level, $cmp);
}

$mqtt = new phpMQTT($server, $port, $client_id);
$lasttelemetry = time();
//$last_seen = 0;
$telemetry_sec = 600;

$ctrl = 0; // off state
$cstate = 0;
$avggrid = 0;
$gridvolts = 240;
$chargepwr = 0;
$chargestart = 0;
$pilot = 0;
// infinite loop here
while (true) {
	if(!$mqtt->connect(true, NULL, $username, $password)) {
		report('openEVSECtrl connect to MQTT - retrying in 10 sec',LOG_ERROR);
		sleep(10);
	} else {
		report('openEVSECtrl to queue:'.$server.':'.$port,LOG_NOTICE);

		$topics['domoticz/out'] = array("qos" => 0, "function" => "procmsg");
		$topics['openevsectrl/cmd'] = array("qos" => 0, "function" => "procmsg");
		$topics[$gridpower_topic] = array("qos" => 0, "function" => "procmsg");
		$topics[$gridvolts_topic] = array("qos" => 0, "function" => "procmsg");
		$topics[$openevse_state_topic] = array("qos" => 0, "function" => "procmsg");
		$topics[$openevse_amps_topic] = array("qos" => 0, "function" => "procmsg");
		$mqtt->subscribe($topics, 0);
		// make first status update
		$mqtt->publish('openevsectrl/cmd','status',0);
		// inform Domo of ctrl state = 0
        $data = new stdClass();
        $data->idx = $domo_idx_ctrl;
        $data->nvalue = 0;
        $data->svalue = '0';
        $msg = JSON_encode($data);
        $mqtt->publish('domoticz/in',$msg,0);

		while($mqtt->proc()){
			$now=time();
			// do telemetry for process
			if($lasttelemetry < $now-$telemetry_sec) {
				$tele = 'openEVSECtrl; status: '.$cstate;
				report($tele,LOG_INFO);
				$lasttelemetry = $now;
			}
			sleep(0.1); // proc() is non-blocking so dont hog the CPU!
		}

		// proc() returned false - reconnect
		report('openEVSECtrl connection - retrying',LOG_NOTICE);
		$mqtt->close();
	}
}

function procmsg($topic, $msg, $retain){
	global $ctrl;
	global $domo_idx_ctrl;
	global $gridpower_topic;
	global $gridvolts_topic;
	global $openevse_state_topic;
	global $openevse_override_set;
	global $openevse_amps_topic;
	global $mqtt;
	global $domo_idx_evpwr;
	global $debug;
	global $start_threshold;
	global $cstate;
	global $pct_add;
	global $pct_sub;
	global $avggrid;
	global $chargepwr;
	global $chargestart;
	global $min_charge;
	global $gridvolts;
	global $pilot;
	global $pilot_max;
	global $pilot_min;
	global $telemetry_sec;
	$now = time();
	// skip retain flag msgs (LWT usually)
	if($retain)
		return;
	// process by topic
	if($debug) echo 'msg from:'.$topic."\n";
	if ($topic=='domoticz/out') {
		# if($debug) echo "domo:".$msg."\n"; // noisy!
		$msg = JSON_decode($msg);
		if($msg->idx == $domo_idx_ctrl){
			// update ctrl state
			$ctrl = intval($msg->svalue1)/10;
			$send = true;
			$data = new stdClass();
			// update OpenEVSE unit accordingly
			if($ctrl==0) {
				$data->state='disable';
				report("charging disabled",LOG_INFO);
			}else if($ctrl==1){
				report("charging from excess",LOG_INFO);
				$send=false; // nothing to do here, gridpower data drives charging state
			}else if($ctrl==2){
				$data->state='active';
				$data->charge_current=$pilot_min;
				report("charging at min pwr",LOG_INFO);
			}else if($ctrl==3){
				$data->state='active';
				$data->charge_current=$pilot_max;
				report("charging at max pwr",LOG_INFO);
			}else{
				report("invalid ctrl value:".$msg->svalue1,LOG_NOTICE);
				$send=false;
			}
			// update OpenEVSE unit with new override data
			if ($send) {
				$msg = JSON_encode($data);
				$mqtt->publish($openevse_override_set,$msg,0);
				report('update OpenEVSE:'.$msg,LOG_INFO);
			}
		}
	}
	if ($topic=='openevsectrl/cmd') {
		if($debug) echo "cmd:".$msg."\n";
		if((empty($msg))|| $msg=='status') {
			$data = new stdClass();
			$data->cmd = "status";
			$data->now = $now;
			$data->ctrl = $ctrl;
			$data->state = $cstate;
			$data->volts = $gridvolts;
			$data->avggrid = $avggrid;
			$data->chargepwr = $chargepwr;
			$data->pilot = $pilot;
			$msg = JSON_encode($data);
			$mqtt->publish('openevsectrl/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
		if($msg=='config') {
			$data = new stdClass();
			$data->cmd = "config";
			$data->debug = $debug;
			$data->grid_topic = $gridpower_topic;
			$data->volts_topic = $gridvolts_topic;
			$data->amps_topic = $openevse_amps_topic;
			$data->domo_idx_evpwr = $domo_idx_evpwr;
			$data->domo_idx_ctrl = $domo_idx_ctrl;
			$data->telemetry_sec = $telemetry_sec;
			$data->pct_add = $pct_add;
			$data->pct_sub = $pct_sub;
			$data->start_threshold = $start_threshold;
			$data->pilot_max = $pilot_max;
			$data->pilot_min = $pilot_min;
			$msg = JSON_encode($data);
			$mqtt->publish('openevsectrl/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
		if($msg=='debug') {
			$debug = !$debug; // toggle and report debug state
			$data = new stdClass();
			$data->cmd = "debug";
			$data->debug = $debug;
			$msg = JSON_encode($data);
			$mqtt->publish('openevsectrl/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
 	}
	else if ($topic==$gridpower_topic) {
		// update grid average power
		$gp = intval($msg);
		$avggrid = $pct_add*$gp + $pct_sub*$avggrid;
		// SKIP further processing unless in excess divert mode
		if($ctrl!=1) return;
		$data = new stdClass();
		$send = false;
		if($debug) echo 'gridpower:'.$gp.' avggrid:'.$avggrid." cstate:".$cstate."\n";
		// if not charging and average export exceeds start threshold, start charge and charge timer
		if(($cstate == 2 || $cstate == 254)&& $avggrid < (0 - $start_threshold)) {
			$pilot = $pilot_min+2; // start over power, so current is reduced and car responds to pilot change
			$data->charge_current = $pilot;
			$data->state = 'active';
			$send = true;
			$chargestart = $now;
			// adjust avggrid by expected power drain to avoid settling time
			$avggrid += $pilot_min*$gridvolts;
		}
		// if charging, adjust charge current to use up excess grid power - apply some deadzone to avoid oscillation
		if($cstate == 3 && $chargepwr > 0) {
			$pilot += $avggrid < -240 && $pilot < $pilot_max ? 1 : 0;
			$pilot -= $avggrid > 240 && $pilot > $pilot_min ? 1 : 0;
			$data->charge_current = $pilot;
			$data->state = 'active';
			$send = true;
		}
		// if charge timer expired, stop charging if import exceeds half the start_threshold 
		if($cstate == 3 && $chargestart < $now-$min_charge && $avggrid > $start_threshold / 2) {
			unset($data->charge_current);
			$data->state = 'disable';
			$send = true;
		}
		// update OpenEVSE unit with new override data
		if ($send) {
			$msg = JSON_encode($data);
			$mqtt->publish($openevse_override_set,$msg,0);
			report('update OpenEVSE:'.$msg,LOG_INFO);
			if($debug) echo 'chargectrl:'.$msg." chargestart:".$chargestart." avggrid:".$avggrid."\n";
		}
	}
	else if ($topic==$gridvolts_topic) {
		// update voltage
		$gridvolts = floatval($msg);
		if($debug) echo 'gridvolts:'.$gridvolts."\n";
	}
	else if ($topic==$openevse_state_topic) {
		// update state
		$cstate = intval($msg);
		if($debug) echo 'state:'.$cstate."\n";
	}
	else if ($topic==$openevse_amps_topic) {
		// update charge power and send to Domo
		$amps = intval($msg);
		$chargepwr = $amps*$gridvolts/1000;
        $data = new stdClass();
        $data->idx = $domo_idx_evpwr;
        $data->nvalue = 0;
        $data->svalue = strval($chargepwr).';0';
        $msg = JSON_encode($data);
        $mqtt->publish('domoticz/in',$msg,0);
		if($debug) echo 'chargepwr:'.$msg."\n";
		// check if there is current in 'off' state and re-assert override if true
		if ($amps > 0 && $ctrl==0) {
			$data = new stdClass();
			$data->charge_current = 0;
			$data->state = 'disable';
			$msg = JSON_encode($data);
			$mqtt->publish($openevse_override_set,$msg,0);
			report('Current detected in off state, update OpenEVSE:'.$msg,LOG_NOTICE);
			if($debug) echo 'Current in off state, chargectrl:'.$msg."\n";
		}
	}
	else {
		// Unknown message source - ignore
		return;
	}
}
