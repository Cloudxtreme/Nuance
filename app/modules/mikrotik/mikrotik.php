<?php
	class mikrotik
	{
		private $connected=false;
		private $API;
		private $id;
    private $db;
		private $notificationaddrlist;
    private $allIpRes = array();
    private $allPppRes = array();
    public $supportQueue;
		public function __construct($ip, $port, $login, $pass, $id=false)
		{
      global $db;
		  if (!class_exists('routeros_api') )require_once('routeros_api.class.php');
      $this->API = new routeros_api();
      $this->messageAddressList='message';
      $this->notificationAddressList='notification';
      $this->API->attempts=2;
      $this->supportQueue=true;
      $this->API->timeout=5;
      $this->API->delay=2;
      $this->API->port=$port;
      $this->db=$db;
		  if ($this->API->connect($ip, $login, $pass))
		  {
		    $this->connected=true;
				$this->id=$id;
      }
      else
      {
        addRequestError('cannotconnect');
      }
        
		}
		function __destruct()
		{
      if ($this->connected) $this->API->disconnect();
    }
    public function checkConnection()
    {
      if ($this->connected) 
      {
        $addressList='online';
        $filterArray=array("?list" => $addressList, );
        $resp=$this->API->comm("/ip/firewall/address-list/print", $filterArray);
        $onlineaddr=array();
        foreach ($resp as $rkey => $rvalue) $onlineaddr[]=$rvalue['address'];

        $resp=$this->API->comm("/system/resource/print");
        $resp[0]['online']=$onlineaddr;
        return $resp[0];
      }
    }

    private function sync($sectionData)
    {
      foreach ($sectionData as $sectionKey => $sectionValue)
      {
        foreach ($sectionValue as $userId => $userData)
        {
					$response = $this->API->comm($sectionKey.'/print',array( '?comment' => $userId));

          for ($i=0; $i<count($userData) || $i<count($response); $i++)
          {
            if (array_key_exists($i, $userData) && array_key_exists($i, $response)) // Check and modify existing rules
            {

              $newRule=$userData[$i];
              $currentRule=$response[$i];

              $ruleNeedsUpdate=false;
              $newProperties=array();

              // Compare every property in rule
              foreach ($newRule as $propertyKey => $propertyValue)
              {
                if ($newRule[$propertyKey]!==$currentRule[$propertyKey])
                {
                  $ruleNeedsUpdate=true;
                  $newProperties[$propertyKey]=$propertyValue;
                }
              }

              if ($ruleNeedsUpdate)
              {
                $newProperties['.id']=$currentRule['.id'];
                $this->API->comm($sectionKey."/set", $newProperties);
              }

            }
            else if (array_key_exists($i, $response)) // remove other rules
            {
              $currentRule=$response[$i];
              $this->API->comm($sectionKey."/remove", array('.id' => $currentRule['.id']));
            }
            else // Add new rules
            {
              $newRule=$userData[$i];
              $newRule['comment'] = $userId;
              $this->API->comm($sectionKey."/add", $newRule);
            }
          }
        }
      }
    }


    private function calculateMikrotikTime($time)
    {
      $timePostfix=array ('h', 'm', 's');
      $mikrotikTime='';

      $timeArray=explode(':', $time);
      for($i=0; $i<count($timeArray); $i++)
      {

        if (intval($timeArray[$i]))
        {
          $mikrotikTime .= intval($timeArray[$i]).$timePostfix[$i];
        }
      }
      if (!$mikrotikTime)
      {
        $mikrotikTime='0s';
      }
      return $mikrotikTime;


    }
    public function checkonline($userid)
    {
      if ($this->connected) return true;
    }
    public function shownotification($userid)
    {
      if ($this->connected)
      {
        if ($devuserres=$this->db->query("SELECT * FROM ".DB_TABLE_PREFIX."user WHERE id='$userid'")->fetchAll())
				{
					$ipRow = $devuserres[0];
					$useraddr=json_decode($ipRow['iplist'],true);
					if (empty($useraddr) || $useraddr==NULL) return false;
          $this->clearnotification($userid);
					foreach ($useraddr as $key => $value)
					{
            $this->API->comm("/ip/firewall/address-list/add", array("list" => $this->notificationAddressList, "address" => $key, "comment" => $ipRow['id'], "disabled" => "no", ));
					}
        }
        return true;
      }
    }
    public function clearnotification($userid=false)
    {
      if ($this->connected)
      {
        $filterArray=array("?list" => $this->notificationAddressList );
        if ($userid) $filterArray['?comment']=$userid;
        $resp=$this->API->comm("/ip/firewall/address-list/print", $filterArray);
        foreach ($resp as $rkey => $rvalue) $this->API->comm("/ip/firewall/address-list/remove", array(".id" => $rvalue['.id'],));
        return true;
      }
    }
    public function showmessage($userid)
    {
      if ($this->connected)
      {
        if ($devuserres=$this->db->query("SELECT * FROM ".DB_TABLE_PREFIX."user WHERE id='$userid'")->fetchAll())
				{
					$ipRow = $devuserres[0];
					$useraddr=json_decode($ipRow['iplist'],true);
					if (empty($useraddr) || $useraddr==NULL) return false;
          $this->hidemessage($userid);
					foreach ($useraddr as $key => $value)
					{
            $this->API->comm("/ip/firewall/address-list/add", array("list" => $this->messageAddressList, "address" => $key, "comment" => $ipRow['id'], "disabled" => "no", ));
					}
        }
        return true;
      }
    }
    public function hidemessage($userid=false)
    {
      if ($this->connected)
      {
        $filterArray=array("?list" => $this->messageAddressList );
        if ($userid) $filterArray['?comment']=$userid;
        $resp=$this->API->comm("/ip/firewall/address-list/print", $filterArray);
        foreach ($resp as $rkey => $rvalue) $this->API->comm("/ip/firewall/address-list/remove", array(".id" => $rvalue['.id'],));
        return true;
      }
    }
    public function getonline()
    {
      if ($this->connected)
      {
        $addressList='online';
        $filterArray=array("?list" => $addressList, );
        $resp=$this->API->comm("/ip/firewall/address-list/print", $filterArray);
        $onlineaddr=array();
        foreach ($resp as $rkey => $rvalue) $onlineaddr[]=$rvalue['address'];
        return $onlineaddr;
      }
    }
    public function getinterfaces()
    {
      if ($this->connected)
      {
        $resp=$this->API->comm("/interface/getall");
        //$response->header=array(array('id', 'varchar'));
        $onlineaddr=array();
        foreach ($resp as $rkey => $rvalue) $onlineaddr[$rvalue['name']]=array($rvalue['name'], $rvalue['name']);
        return $onlineaddr;
      }
    }

    private function getIpRows( $userId ) {
      if ( !isset( $this->allIpRes[ $userId ] ) ) {
        $ipTable = new Table( 'ip' );
        $this->allIpRes[ $userId ] = $ipTable->load( "WHERE `router`=$this->id AND `user`=$userId" );
      }
      return $this->allIpRes[ $userId ];
    }

    private function getPppRows( $userId ) {
      if ( !isset( $this->allPppRes[ $userId ] ) ) {
        $pppTable = new Table( 'ppp' );
        $this->allPppRes[ $userId ] = $pppTable->load( "WHERE `router`=$this->id AND `user`=$userId" );
      }
      return $this->allPppRes[ $userId ];
    }

    public function update( $userId ) {
      $usersTable = new Table( 'user' );

      $allIpRes = $this->getIpRows( $userId );
      $allPppRes = $this->getPppRows( $userId );

      foreach ($allIpRes as $allIpRow ) 
      {
        $this->updateIp( $allIpRow[ 'id' ], $userId );
      }

      foreach ($allPppRes as $allPppRow ) 
      {
        $this->updatePpp( $allPppRow[ 'id' ], $userId );
      }

      $this->updateAddressList( $userId );
      return $this->updateQueue( $userId ); //Return connection state

    }


    public function updateAddressList( $userId ) {
    
      $syncData=array(
        "/ip/firewall/address-list" => array()
      );

      foreach ($syncData as $key => $value)
      {
        $syncData[$key][$userId] = array();
      }

      $currentTariff= getCurrentTariff( $userId );
      if ($currentTariff)
      {
        $currentTariff= $currentTariff['detailsid'];
      }
      if ( userIsDisabled( $userId ) )
      {
        $addressList = 'disabled';
      }
      else
      {
        $addressList = $currentTariff ? 'allow' : 'deny';
      }

      $allIpRes = $this->getIpRows( $userId );
      $allPppRes = $this->getPppRows( $userId );


      foreach ( $allIpRes as $ipRow ) {
        $syncData["/ip/firewall/address-list"][$userId][] = array(
          "list" => $addressList,
          "address" => $ipRow['ip']
        );
      }

      foreach ( $allPppRes as $pppRow ) {
        $syncData["/ip/firewall/address-list"][$userId][] = array(
          "list" => $addressList,
          "address" => $pppRow['remoteip']
        );
      }

      d($syncData);

      $this->sync($syncData);

      return $this->checkConnection();
    }

    public function updateQueue ( $userId ) {

      $allIpRes = $this->getIpRows( $userId );
      $allPppRes = $this->getPppRows( $userId );
      $ipList=array();
        foreach ($allIpRes as $allIpRow ) 
        {
          $ipList[]=$allIpRow['ip']."/32";
        }

        foreach ($allPppRes as $allPppRow ) 
        {
          $ipList[]=$allPppRow['remoteip']."/32";
        }

      $syncData=array(
        "/queue/simple" => array()
      );

      $currentTariff= getCurrentTariff( $userId );
      if ($currentTariff)
      {
        $currentTariff= $currentTariff['detailsid'];
      }
      
      if ($currentTariff)
      {
        $tariffTable=new Table('tariff');
        $utariffres=$tariffTable->load("WHERE id=$currentTariff");
        $utariffrow = $utariffres[0];
        if ($utariffrow)
        {
          // Normal / day
          if (pluginExists('night') &&( $utariffrow['nightupspeed']  || $utariffrow['nightdownspeed'] ) )
          {
            $dayTime  = $this->calculateMikrotikTime ( configgetvalue('system', 'tariff', NULL, 'nightHourEnd') );
            $dayTime .= '-1d';
            //$dayTime .= '-'.$this->calculateMikrotikTime ( configgetvalue('system', 'tariff', NULL, 'nightHourStart') );
            $dayTime .= ',sun,mon,tue,wed,thu,fri,sat';
          }
          else
          {
            $dayTime  = '0s-1d,sun,mon,tue,wed,thu,fri,sat';
          }
          // Burst
          if ( pluginExists('burst') &&
               $utariffrow['downburstlimit'] &&
               $utariffrow['upburstlimit'] &&
               $utariffrow['downburstthreshold'] &&
               $utariffrow['upburstthreshold'] &&
               $utariffrow['downbursttime'] &&
               $utariffrow['upbursttime'] 
             )
          {
            $burstLimit=$utariffrow['upburstlimit'].'/'.$utariffrow['downburstlimit'];
            $burstThreshold=$utariffrow['upburstthreshold'].'/'.$utariffrow['downburstthreshold'];
            $burstTime=$utariffrow['upbursttime'].'/'.$utariffrow['downbursttime'];
          }
          else
          {
            $burstLimit="0/0";
            $burstThreshold="0/0";
            $burstTime="0s/0s";
          }

          // Speed
          $speed= $utariffrow['upspeed']."/".$utariffrow['downspeed'];

          
          // Select right target addresses index
          if ($majorVersion===5)
          {
            $addressIndex='target-addresses';
          }
          else
          {
            $addressIndex='target';
          }

          $dbadrr=implode(',', $ipList);
          if (count($allIpRes))
          {
            $syncData["/queue/simple"][$userId][] = array(
              "limit-at" => $utariffrow['upspeed']."/".$utariffrow['downspeed'],
              "max-limit" => $utariffrow['upspeed']."/".$utariffrow['downspeed'],
              $addressIndex => $dbadrr,
              "name" => $userIdId,
              "time" => $dayTime,
              "burst-limit"=> $burstLimit,
              "burst-threshold"=> $burstThreshold,
              "burst-time"=> $burstTime
            );
          }



          // Night

          if (pluginExists('night') &&( $utariffrow['nightupspeed']  || $utariffrow['nightdownspeed'] ) )
          {
            $nightSpeed= $utariffrow['nightupspeed']."/".$utariffrow['nightdownspeed'];

            //$time  = $this->calculateMikrotikTime ( configgetvalue('system', 'tariff', NULL, 'nightHourStart') );
            $time  = '0s';
            $time .= '-'.$this->calculateMikrotikTime ( configgetvalue('system', 'tariff', NULL, 'nightHourEnd') );
            $time .= ',sun,mon,tue,wed,thu,fri,sat';

            if (count($allIpRes))
            {
              $syncData["/queue/simple"][$userId][] = array(
                "limit-at" => $nightSpeed,
                "max-limit" => $nightSpeed,
                $addressIndex => $dbadrr,
                "name" => $userIdId.'-night',
                "time" => $time,
                "burst-limit"=> $burstLimit,
                "burst-threshold"=> $burstThreshold,
                "burst-time"=> $burstTime
              );
            }
          }


        }
      }
      $this->sync($syncData);

      return $this->checkConnection();
    }

		public function updateIp( $id, $userId )
		{
			if ($this->connected)
			{
        $resource=$this->checkConnection();
        $majorVersion=intval($resource['version'][0]);
        $ipTable=new Table('ip');
        $ipRes=$ipTable->load(" WHERE id=$id");
				if (!$ipRes) return;


        $syncData=array(
          "/ip/firewall/filter" => array(),
          "/ip/firewall/mangle" => array(),
          "/ip/arp" => array(),
          "/queue/simple" => array(),
          "/ip/dhcp-server/lease" => array()
        );

        foreach ($ipRes as $ipRow)
				{
          $ipId=''.$ipRow['id'];

          foreach ($syncData as $key => $value)
          {
            $syncData[$key][$userId] = array();
          }

          $ip = $ipRow[ 'ip' ];
          $mac = preg_replace('/..(?!$)/', '$0:', strtoupper($ipRow[ 'mac' ]));
          
      
        
					//	MAC-filter	section


          $macFilterType=configgetvalue('router', 'main', $this->id, 'filterType');
          switch ($macFilterType)
          {
            case 1: //Block by ARP
            {
              $inInterface=configgetvalue('router', 'main', $this->id, 'inInterface');

              if (strlen($mac))
              {
                $syncData["/ip/arp"][$userId][] = array(
                  "mac-address" => $mac,
                  "address" => $ip,
                  "interface" => $inInterface
                );
              }
            }
            break;
            case 2: //Block by filter rule
            {
              if (strlen($mac))
              {
                $syncData["/ip/firewall/filter"][$userId][] = array(
                  "list" => $addressList,
                  "address" => $ip,
                  "action" => "drop",
                  "chain" => "forward",
                  "src-mac-address" => "!".$mac,
                  "src-address" => $ip
                );
              }
            }
            break;
            case 3: //Block by mangle
            {
              if (strlen($mac))
              {
                $syncData["/ip/firewall/mangle"][$userId][] = array(

                  "action" => "mark-connection",
                  "new-connection-mark" => "badmac",
                  "chain" => "prerouting",
                  "src-mac-address" => "!".$mac,
                  "src-address" => $ip
                );
              }
            }
            break;
          }
				
          
					//	DHCP section

        

          if (strlen($mac))
          {
            $syncData["/ip/dhcp-server/lease"][$userId][] = array(
              "address" => $ip, 
              "mac-address" => $mac
            );
          }

          $this->sync($syncData);

					return $this->checkConnection();

				}
			}
		}
	
    public function updatePpp( $id, $userId )
		{
			if ($this->connected)
			{
        $resource=$this->checkConnection();
        $majorVersion=intval($resource['version'][0]);

        $pppTable=new Table('ppp');
        $pppRes=$pppTable->load("WHERE id=$id");
				if (!$pppRes) return;

        

        foreach ($pppRes as $pppRow)
        {
          $syncData=array(
            "/queue/simple" => array(),
            "/ppp/secret" => array()
          );
          $id=''.$pppRow['id'];
          
          foreach ($syncData as $key => $value)
          {
            $syncData[$key][$userId] = array();
          }

          $login = $pppRow[ 'login' ];
          $password = $pppRow[ 'password' ];

          $localIp = $pppRow[ 'localip' ];
          $remoteIp = $pppRow[ 'remoteip' ];

          $pppService = $pppRow[ 'pppservice' ];

          //	PPP section
          //	
          $disableSecretsForDisabledUsers=configgetvalue('router', 'ppp', $this->id, 'disablePPPSecretsOfBlockedUsers'); 
          $usersTable = new Table( 'user' );
          $user = $usersTable->loadById( $pppRow['user'] );
          $disabledState=( $user['disabled']=='1' && $disableSecretsForDisabledUsers) ? 'yes' : 'no';
          
          $syncData["/ppp/secret"][$userId][] = array(
            "service" => $pppService,
            "profile" => "default",
            "local-address" => $localIp,
            "remote-address" => $remoteIp,
            "disabled" => $disabledState,
            "name" => $login,
            "password" => $password
          );


				}
        $this->sync($syncData);

        return $this->checkConnection();
			}
		}
	
	
		public function getmac($ip)
		{
			if ($this->connected)
			{
				$response = $this->API->comm('/ip/arp/print',array( '?address' => $ip, '?disabled' => 'false' ));
        if ( count($response) )
        {
          $row=$response[0];
          if ($row['dynamic']==='false')
          {
            // Disable static ARP entry in case it was created by billing
            // It will give possibility to detect current MAC from ARP
            $this->API->comm("/ip/arp/set", array(".id" => $row['.id'], "disabled" => 'yes' ) );
            sleep(9);
            $newResponse = $this->API->comm('/ip/arp/print',array( '?address' => $ip, '?disabled' => 'false' ));
            // Enable static ARP entry again
            $this->API->comm("/ip/arp/set", array(".id" => $row['.id'], "disabled" => 'no' ) );

            if ($newResponse)
            {
              $newRow=$newResponse[0];
              return strtoupper($newRow['mac-address']);
            }
          }
          else
          {
            return strtoupper($row['mac-address']);
          }
        }
			}
		}
		public function export()
		{
      if ($this->connected)
      {
        $usersTable=new Table('user');
        $res=$usersTable->load(" WHERE router=".$this->id);
        if ($res)
        {
          foreach ($res as $row)
          {
            $this->update($row['id']);
          }
          return $this->checkConnection();
        }
      }
		}
	}
?>
