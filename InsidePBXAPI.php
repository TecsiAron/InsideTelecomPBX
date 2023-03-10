<?php
/**
 * @version 1.0.0-alpha
 * @author Editura EDU
 * @license MIT
 * MIT License
 * Copyright (c) 2022 Editura EDU
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
/** @noinspection PhpMultipleClassesDeclarationsInOneFile
 */

class InsidePBXAPI
{
    private const TENANT_ID="INERT_TENTANT_ID_HERE";
    private const USER_TYPE="TENANT";
    private const TOKEN="INSERT_TOKEN_HERE";
    private const DEBUG=true;

    /**
     * Value should not contain trailing /
     */
    private const API_BASE_URL="https://portal.insidetelecom.ro/InsideTelecom_api/v1.4/api";

    /**
     * Strips tenant ID from extension (if tenant= 1000 1000101 becomes 101)
     * @param string $extension
     * @return string striped extension number
     */
    public static function StripTenantIDFromExtension(string $extension):string
    {
        return str_replace(self::TENANT_ID,"", $extension);
    }

    /**
     * Checks if given phone number is valid (contains only digits after normalization and is between given lengths)
     * Note: As part of E. 164 standard phone numbers shouldn't have more than 15 chars!
     */
    public static function IsValidPhoneNumber(string $telephone, int $minDigits = 10, int $maxDigits = 15): bool
    {
        $telephone= self::NormalizePhoneNumber($telephone);
        return preg_match('/^[0-9]{'.$minDigits.','.$maxDigits.'}\z/', $telephone);
    }

    /**
     * Removes spaces, dots, dashes and parenthesis; replaces + with 00
     */
    public static function NormalizePhoneNumber(string $telephone): string {
        $telephone = str_replace([' ', '.', '-', '(', ')'], '', $telephone);
        if (preg_match('/^[+][0-9]/', $telephone)) { //is the first character + followed by a digit
            $telephone = substr_replace($telephone, "00", 0, 1);
        }
        return $telephone;
    }

    /**
     * Uses the callLog API method to get calls that where hung up by the customer while not forwarded to a known agent extension
     * Uses GetExtension API call to get list of known agent extensions
     * To Be more specific, a call is considered abandoned if all the following are true:
     * hangup_by = CALLER
     * the contents of forward are not in the list of known extensions
     * forward_name is in the given que names array or is the after hours forward_name
     * the call direction is inbound
     * @param array $queNames que names in a numerically indexed array
     * @param int $start unix timestamp
     * @param int $end unix timestamp
     * @param ?string $afterHoursForwardName name of the after hours playback item (forward_name)
     * @throws Exception if status code isn't 200>=http statuscode<=299
     */
    public function GetAbandonedCalls(array $queNames, int $start, int $end, ?array $afterHoursForwardName = null):array
    {
        $extensions=$this->GetExtensions(true);
        $hasAfterHourMessage=false;
        if(!empty($afterHoursForwardName))
        {
            if(is_array($afterHoursForwardName))
            {
                $hasAfterHourMessage = true;
                $queNames = array_merge($queNames,$afterHoursForwardName);
            }
        }
        $abandonedCalls=[];
        $callMap=[];
        for($i=0; $i<sizeof($queNames); $i++)
        {
            $abandonedCalls[$queNames[$i]]=[];
        }
        $startDate= new DateTime();
        $endDate= new DateTime();
        $startDate->setTimestamp($start);
        $endDate->setTimestamp($end);
        $url="info/".$startDate->format("Y-m-d-H:i")."/".$endDate->format("Y-m-d-H:i")."/".self::USER_TYPE."/callLog";
        [$result, $status]=$this->CallAPI($url);
        $this->CheckStatusCode($status);
        $result=json_decode($result);
        if(isset($result->status))
        {
            if($result->status=="SUCCESS")
            {
                if($result->count==0)
                {
                    return $abandonedCalls;
                }
                $allCalls = $result->data;
                for($i=0; $i<sizeof($allCalls); $i++)
                {
                    $callMetadata=$allCalls[$i];
                    if(in_array($hasAfterHourMessage, $afterHoursForwardName))
                    {
                        if ($callMetadata->forward_name == $afterHoursForwardName)
                        {
                            $callMetadata->hangup_by = "CALLER";
                        }
                    }
                    if(self::DEBUG)
                    {
                        echo"CHECKER VARS:<br>\n";
                        var_dump($callMetadata->unique_token,
                            $callMetadata->hangup_by=="CALLER" ,
                        !in_array($callMetadata->forward,$extensions),
                        in_array($callMetadata->forward_name,$queNames),
                        strtolower($callMetadata->call_direction)==CallConstants::DIRECTION_INBOUND);
                        echo "<br>\n";
                    }
                    if($callMetadata->hangup_by=="CALLER" && !in_array($callMetadata->forward,$extensions) 
                    && in_array($callMetadata->forward_name,$queNames) 
                    &&  strtolower($callMetadata->call_direction)==CallConstants::DIRECTION_INBOUND)
                    {
                        $callInfo = $this->ConvertCallLogMetadataToCallInfo($callMetadata);
                        $abandonedCalls[$callMetadata->forward_name][]=$callInfo;
                        $callMap[$callInfo->CallerNumber][]=[$callInfo->CallAnswerTime, $callMetadata->forward_name,  sizeof($abandonedCalls[$callMetadata->forward_name])-1];
                    }
                    else if(in_array($callMetadata->forward,$extensions))
                    {
                        $callInfo = $this->ConvertCallLogMetadataToCallInfo($callMetadata);
                        if(!empty($callMap[$callInfo->CallerNumber]))
                        {
                            for($j=0; $j<sizeof($callMap); $j++)
                            {
                                [$answerTime, $queName, $index]=$callMap[$callInfo->CallerNumber][$j];
                                if($answerTime<$callInfo->CallAnswerTime)
                                {
                                    if(self::DEBUG)
                                    {
                                        echo "Removing " . $abandonedCalls[$queName][$index]->UUID . " because " . $callInfo->UUID . " is newer and answered<br>\n";
                                    }
                                    unset($abandonedCalls[$queName][$index]);
                                }
                            }
                        }

                    }

                }
            }
        }
        for($i=0; $i<sizeof($queNames); $i++)
        {
            $abandonedCalls[$queNames[$i]]=array_values($abandonedCalls[$queNames[$i]]);
        }
        return $abandonedCalls;
    }

    private function ConvertCallLogMetadataToCallInfo($callMetadata):CallInfo
    {
        $callInfo = new CallInfo();
        $callInfo->UUID=$callMetadata->unique_token;
        $callInfo->Status=CallConstants::STATE_ABANDONED;
        $callInfo->Direction=CallConstants::DIRECTION_INBOUND;
        $callInfo->CallAnswerTime=DateTime::createFromFormat("Y-m-d H:i:s",$callMetadata->start_date, new DateTimeZone(CallConstants::CUSTOM_TIMEZONE_NAME===false? date_default_timezone_get():CallConstants::CUSTOM_TIMEZONE_NAME))->getTimestamp();
        $callInfo->CallDuration=$callMetadata->call_sec;
        $callInfo->CallEndTime=$callInfo->CallAnswerTime+$callInfo->CallDuration;
        $callInfo->HangupReason=$callMetadata->hangup_reason;
        $callInfo->CallerName=$callMetadata->caller_name;
        $callInfo->CallerNumber=$callMetadata->caller;
        echo $callMetadata->caller."<br>";
        return  $callInfo;
    }

    /**
     * Fetches all known extensions and there metadata
     * @param bool $onlyNumbers if set to true, only the extension numbers are returned
     * @return array array of extensions with metadata/extension numbers, see above.
     * @throws Exception if status code isn't 200>=http statuscode<=299
     */
    public function GetExtensions(bool $onlyNumbers=false):array
    {
        $url="info/".self::USER_TYPE."/getExtension";
        [$apiResult,$statusCode]=$this->CallAPI($url);
        $this->CheckStatusCode($statusCode);
        $result=json_decode($apiResult);
        $extensions=[];
        if(isset($result->status))
        {
            if($result->status=="SUCCESS")
            {
                if($onlyNumbers)
                {
                    for($i=0; $i<sizeof($result->data); $i++)
                    {
                        $extensions[]=$result->data[$i]->ext_number;
                    }
                }
                else
                    return $result->data;
            }
        }
        return $extensions;
    }

    /**
     * Fetches all (external) phonebook entries with a valid phone number
     * @return array
     * @throws Exception if status code isn't 200>=http statuscode<=299
     */
    public function GetPhoneBook():array
    {
        $url="info/".self::USER_TYPE."/getPhoneNumber";
        [$apiResult,$statusCode]=$this->CallAPI($url);
        $this->CheckStatusCode($statusCode);
        $result=json_decode($apiResult);
        if(isset($result->status))
        {
            if($result->status=="SUCCESS")
            {
                $phoneBook=[];
                $phoneNumberProperty="Phone Number";
                for($i=0; $i<sizeof($result->data); $i++)
                {
                    if(!empty($result->data[$i]->$phoneNumberProperty))//only return entries with a phone number
                    {
                        $result->data[$i]->$phoneNumberProperty=InsidePBXAPI::NormalizePhoneNumber($result->data[$i]->$phoneNumberProperty);
                        $phoneBook[$result->data[$i]->$phoneNumberProperty] = $result->data[$i];
                    }
                }
                return $phoneBook;
            }
        }
        return [];
    }

    /**
     * Creates new phonebook entry
     * @param string $firstName
     * @param string $lastName
     * @param string $displauName
     * @param string $PhoneNumber
     * @return bool true if successful
     */
    public function CreatePhoneBookEntry(string $firstName, string $lastName, string $displauName, string $PhoneNumber):bool
    {
        $url = "add/".self::USER_TYPE."/createNumber";
        $data["pb_first_name"]=$firstName;
        $data["pb_last_name"]=$lastName;
        $data["pb_display_name"]=$displauName;
        $data["pb_phone_number"]=$PhoneNumber;
        try {
            [$apiResult,$statusCode]=$this->CallAPI($url,$data);
            $this->CheckStatusCode($statusCode);

        }
        catch (Exception $e)
        {
            if(self::DEBUG) {
                var_dump($apiResult, $statusCode, $data, $e);
            }
            return false;
        }
        $result=json_decode($apiResult);
        if($result->status!="SUCCESS")
        {
            if(self::DEBUG) {
                var_dump($apiResult, $statusCode, $data);
            }
            return false;
        }
        return  true;
    }

    /**
     * Updates phonebook entry (Currently uses Delete/Create instead of update, to avoid an API bug)
     * @param int $phoneBookID
     * @param string $firstName
     * @param string $lastName
     * @param string $displayName
     * @param string $phoneNumber
     * @return bool true if successful
     */
    public function UpdatePhoneBookEntry(int $phoneBookID, string $firstName, string $lastName, string $displayName, string $phoneNumber):bool
    {
        if(!$this->DeletePhoneBookEntry($phoneBookID))
        {
            return false;
        }
        return $this->CreatePhoneBookEntry($firstName,$lastName,$displayName,$phoneNumber);
        /// At the time of wriging  the updatePhonebook API call doesn't work as expected, so we use delete/create instead
        /*$data["phonebook_id"]=$phoneBookID;
        $data["pb_first_name"]=$firstName;
        $data["pb_last_name"]=$lastName;
        $data["pb_display_name"]= $displayName;
        $url="update/".self::USER_TYPE."/updatePhoneNumber";
        try {
            [$apiResult,$statusCode]=$this->CallAPI($url,$data);
            $this->CheckStatusCode($statusCode);

        }
        catch (Exception $e)
        {
            if(self::DEBUG)
            {
                var_dump($apiResult,$statusCode,$data);
            }
            return false;
        }
        $result=json_decode($apiResult);
        if($result->status!="SUCCESS")
        {
            if(self::DEBUG)
            {
                var_dump($apiResult,$statusCode,$data);
            }
            return false;
        }
        return  true;*/
    }

    /**
     * Deletes a phonebook entry
     * @param int $phoneBookID
     * @return bool true if successful
     */
    public function DeletePhoneBookEntry(int $phoneBookID):bool
    {
        $data["phonebook_id"]=$phoneBookID;
        $url="delete/".self::USER_TYPE."/deletePhoneNumber";
        try {
            [$apiResult,$statusCode]=$this->CallAPI($url,$data);
            $this->CheckStatusCode($statusCode);

        }
        catch (Exception $e)
        {
            $e->getMessage();
            return false;
        }
        $result=json_decode($apiResult);
        if($result->status!="SUCCESS")
        {
            if(self::DEBUG)
            {
                var_dump($apiResult, $statusCode, $data);
            }
            return false;
        }
        return  true;
    }

    /**
     * Checks if the given status code is successful otherwise throws Exception
     * @param int $statusCode
     * @return void
     * @throws Exception if status code is not between 200 and 299 (inclusive)
     */
    private function CheckStatusCode(int $statusCode): void
    {
        if($statusCode>=200 && $statusCode<300)
        {
            return;
        }
        throw(new Exception("API resulted in $statusCode status code!", $statusCode));
    }

    /**
     * Calls the API with the given url and returns the result(string) and status code(int)
     * @param string $CommandURL the url without preceding /
     * @param array $parameters optional data to send
     * @return array  0 is result string 1 is status code
     */
    private function CallAPI(string $CommandURL, array $parameters =[]):array
    {
        $postData["token_id"]=self::TOKEN;
        $postData=array_merge($postData, $parameters);
        $postData=json_encode($postData);
        $finalURL=self::API_BASE_URL . '/' . $CommandURL;
        $Curl = curl_init();
        curl_setopt($Curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($Curl, CURLOPT_CONNECTTIMEOUT, 2000);
        curl_setopt($Curl, CURLOPT_TIMEOUT, 3000);
        curl_setopt($Curl, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($Curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($Curl, CURLOPT_URL, $finalURL);
        
        $result = curl_exec($Curl);
        $header = curl_getinfo($Curl);
        $output[0] = $result;
        $output[1] = $header['http_code'];
        if(self::DEBUG)
        {
            echo"POST DATA:<br>\n";
            var_dump($postData);
            echo"URL:<br>\n";
            var_dump($finalURL);
            echo"Output:<br>\n";
            var_dump($output);
        }
        return $output;
    }
}

/**
 * Used instead of an enum, since enums are not supported in PHP 7.4
 */
class CallConstants
{
    public const STATE_RING="Ring";
    public const STATE_HANGUP="Hangup";
    public const STATE_DIAL="Dial";
    public const STATE_ANSWER="Answer";
    public const STATE_ABANDONED="ABANDONED";
    public const DIRECTION_INBOUND="inbound";
    public const DIRECTION_OUTBOUND="outbound";
    public const REASON_NORMAL="NORMAL_CLEARING";
    public const REASON_ORIGINATOR_CANCEL="ORIGINATOR_CANCEL";
    public const REASON_USER_BUSY="USER_BUSY";
    /**
     * @const string|bool
     */
    public const CUSTOM_TIMEZONE_NAME="Europe/Bucharest";
}

class CallInfo
{
    public string $UUID;
    public string $Extension;
    public ?string $CallerName=null;
    public ?string $CallerNumber=null;

    public string $Status;
    public string $Direction;
    public ?int $CallAnswerTime=null;
    public ?int $CallDuration=null;
    public ?int $CallEndTime=null;
    public ?string $HangupReason=null;

    /***
     * Converts an API call metadata result to CallInfo
     * IMPORTANT will use CUSTOM_TIMEZONE_NAME to convert recived time.
     * Alternatively (if CUSTOM_TIMEZONE_NAME===false) will use the servers default timezone
     * @param array|null $request
     * @return CallInfo|null null if $request is null
     */
    public static function CreateFromCallback(?array $request):?CallInfo
    {
        $requestDump = print_r($_REQUEST, TRUE);
        if($request==null)
        {
            return null;
        }
        if(empty($request["uuid"]))
        {

            error_log("Failed to convert request to CallInfo ($requestDump)");
            return null;
        }
        $result = new CallInfo();
        $map=["uuid"=>"UUID", "extension_number"=>"Extension", "caller_name"=>"CallerName", "caller_number"=>"CallerNumber",
            "call_status"=>"Status", "call_direction"=>"Direction",
            "call_duration"=>"CallDuration", "reason"=>"HangupReason"];
        foreach ($map as $PostName => $FieldName)
        {
            if(!empty($request[$PostName]))
            {
                $result->$FieldName=$request[$PostName];
            }
        }
        $result->Extension=InsidePBXAPI::StripTenantIDFromExtension($result->Extension);
        if(isset($request["call_answer_time"]) )
        {
            if($request["call_answer_time"]!="0000-00-00 00:00:00")
            {
                $answerDateTime=DateTime::createFromFormat("Y-m-d H:i:s",$request["call_answer_time"], new DateTimeZone(CallConstants::CUSTOM_TIMEZONE_NAME===false? date_default_timezone_get():CallConstants::CUSTOM_TIMEZONE_NAME));
                if($answerDateTime!==false)
                {
                    $result->CallAnswerTime=$answerDateTime->getTimestamp();
                    if($result->CallDuration!=null)
                    {
                        $result->CallEndTime=$result->CallAnswerTime+$result->CallDuration;
                    }
                }
            }
        }
        return $result;
    }
}

