<?php

namespace Fleetbase\FleetOps\Support;

use Webit\Util\EvalMath\EvalMath;

class Algo
{
    /**
     * Execute an algorithm strig.
     *
     * @return int
     */
    public static function exec($algorithm, $variables = [], $round = false)
    {
        $m                  = new EvalMath();
        $m->suppress_errors = true;

        foreach ($variables as $key => $value) {
            $algorithm = str_replace('{' . $key . '}', $value, $algorithm);
        }

        $result = $m->evaluate($algorithm);

        if ($round) {
            return round($result, 2); // precision 2 cuz most likely dealing with $
        }

        return $result;
    }

    /**
     * Calculates driving distance and time using Google distance matric.
     *
     * @return array
     */
    public static function calculateDrivingDistanceAndTime($lat1, $long1, $lat2, $long2)
    {
        $url =
            'https://maps.googleapis.com/maps/api/distancematrix/json?origins=' . $lat1 . ',' . $long1 . '&destinations=' . $lat2 . ',' . $long2 . '&mode=driving&key=' . config('services.google.key');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_PROXYPORT, 3128);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $response = curl_exec($ch);
        curl_close($ch);
        $response_a = json_decode($response, true);
        $dist       = $response_a['rows'][0]['elements'][0]['distance']['value'];
        $time       = $response_a['rows'][0]['elements'][0]['duration']['value'];

        return ['distance' => $dist, 'time' => $time];
    }
}
