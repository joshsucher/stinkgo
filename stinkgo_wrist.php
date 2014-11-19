<?php

error_reporting(0);

include('wiringpi.php'); 
$pin = 1; 
wiringpi::pinMode($pin,2); // pwm mode

require_once('geohash.class.php');
$geohash=new Geohash;

global $head, $blurb, $title, $showmap, $autorefresh, $footer, $gmap_key;
global $server, $advertise, $port, $open, $swap_ew, $testmode;

set_time_limit(3);
ini_set('max_execution_time', 3);

require_once("gpsd_config.inc");

$fp = fopen("/var/www/log.csv","a");

function map($value, $fromLow, $fromHigh, $toLow, $toHigh) {
    $fromRange = $fromHigh - $fromLow;
    $toRange = $toHigh - $toLow;
    $scaleFactor = $toRange / $fromRange;

    // Re-zero the value within the from range
    $tmpValue = $value - $fromLow;
    // Rescale the value to the to range
    $tmpValue *= $scaleFactor;
    // Re-zero back to the to range
    return $tmpValue + $toLow;
}

function haversineGreatCircleDistance(
  $latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000)
{
  // convert from degrees to radians
  $latFrom = deg2rad($latitudeFrom);
  $lonFrom = deg2rad($longitudeFrom);
  $latTo = deg2rad($latitudeTo);
  $lonTo = deg2rad($longitudeTo);

  $latDelta = $latTo - $latFrom;
  $lonDelta = $lonTo - $lonFrom;

  $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
    cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
  return $angle * $earthRadius;
}

//include "gpsd.php";

		$sock = @fsockopen($server, $port, $errno, $errstr, 2);
		@fwrite($sock, "?WATCH={\"enable\":true}\n");
		usleep(1000);
		@fwrite($sock, "?POLL;\n");
		usleep(1000);
		for($tries = 0; $tries < 10; $tries++){
			$resp = @fread($sock, 2000); # SKY can be pretty big
			if (preg_match('/{"class":"POLL".+}/i', $resp, $m)){
				$resp = $m[0];
				break;
			}
		}
		@fclose($sock);

$json = json_decode($resp, true);

$myLat = $json["tpv"][0]["lat"];
$myLng = $json["tpv"][0]["lon"];
$epx = $json["tpv"][0]["epx"];
$epy = $json["tpv"][0]["epy"];
$mode = $json["tpv"][0]["mode"];

//echo "mylat: $myLat ";
//echo "myLng: $myLng ";

$hash = $geohash->encode($myLat,$myLng);
$hash = substr($hash,0,6);

$dbconn = new PDO('sqlite:/var/www/stinkgo_geohashed.sqlite');

$squery = $dbconn->prepare('select lat,lng from stinkgo_geohashed where geohash=?');
$squery->bindParam(1,$hash);
$squery->execute();

$result = $squery->fetchAll();

foreach ($result as $stinkgo) {

        $lat=$stinkgo['lat'];
        $lng=$stinkgo['lng'];

$distance = haversineGreatCircleDistance($myLat, $myLng, $lat, $lng, 6371);

if(($distance*3280.84) <= 100) {

if((is_null($stinkdistance)) || ($distance*3280.84 < $stinkdistance)) {

$stinks = floatval($stinkgo['lat']) . "," . floatval($stinkgo['lng']) . "," . $distance*3280.84 . "," . $myLat . "," . $myLng . "," . $epx . "," . $epy . "," . date("n/j/y G:i:s");

fwrite($fp,$stinks."\n");

$stinkdistance = $distance*3280.84;

//echo "lat: $lat ";
//echo "lng: $lng ";
//echo "dst: " . $distance*3280.84 ." ";

}
}

}


if(isset($stinkdistance)) {
if($mode == 3) {
$stink = map($stinkdistance,100,0,500,1024);
wiringpi::pwmWrite($pin, $stink);
//echo "stnk: $stink ";
//echo "stnkdist: $stinkdistance ";
}
else {
wiringpi::pwmWrite($pin, 0);
}
}
else {
wiringpi::pwmWrite($pin, 0);
//echo "nostink";
$stinks = $myLat . "," . $myLng . "," . $epx . "," . $epy . "," . date("n/j/y G:i:s");

fwrite($fp,$stinks."\n");

}

fclose($fp);

?>