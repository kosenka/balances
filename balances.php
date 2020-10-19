#!/usr/bin/php
<?php
    /*
     *
     * Проверка баланса для Zabbix 5.0.2
     *
     * Последняя проверка работоспособности скрипта была проведена 19.10.2020
     *
     * 1) Помещаем этот файл в /usr/lib/zabbix/externalscripts
     * 1.1) chmod 0744 /usr/lib/zabbix/externalscripts/balances.php
     * 1.2) chown zabbix:zabbix /usr/lib/zabbix/externalscripts/balances.php
     *
     * 2) В Zabbix создаем узел сети, balances.
     * В шаблоне этого узла создаем один элемент данных:
     * Имя: mango
     * Тип: Внешняя проверка
     * Ключ: balances.php["mango","{$MANGO_LOGIN}","{$MANGO_PASSWORD}"]
     * Тип информации: Число с плавающей точкой
     * Интервал обновления: 1d
     *
     * 3) Проверка окончания срока регистрации домена:
     * Имя: balance
     * Тип: Внешняя проверка
     * Ключ: balances.php["domain","mos.ru"]
     * Тип информации: Число с плавающей точкой
     * Интервал обновления: 1d
     *
     * Примеры:
     *      balances.php domain yandex.ru - вернется строка: "120 (2020-02-02)"
     *      balances.php beeline 71234567 password
     *      balances.php tele2 71234567 password
     *      balances.php mango 71234567 password - https://www.mango-office.ru/
     *      balances.php sevensky F-123456-654321 password - интернет провайден SevenSky https://www.seven-sky.net/
     *      balances.php mosenergosbyt 71234567 password - возвращает задолженность от Мосэнергосбыт (вход по номеру телефона) https://my.mosenergosbyt.ru/auth
     *      balances.php yandexdirect login@yandex.ru password - возвращает кол-во денег в Яндекс.Директ https://direct.yandex.ru/registered/main.pl
     *      balances.php yandexmarket login@yandex.ru password - возвращает кол-во денег в Яндекс.Маркет https://partner.market.yandex.ru/shop/
     *
     *
    */
    class balances
    {
        public $userAgent = "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/61.0.3163.100 Safari/537.36";

        public $headers = array();

        public $cookieFile;
        public $referer;

        public $curlConnectTimeout = 10;
        public $curlTimeout = 15;

        public $provider;
        public $login;
        public $username;

        function __construct()
        {
            $this->cookieFile = stream_get_meta_data(tmpfile())['uri'];
        }

        public function pre($t)
        {
            return '<pre>'.print_r($t,true).'</pre>';
        }

        public function removeBOM($str="") {
            if(substr($str, 0, 3) == pack('CCC', 0xef, 0xbb, 0xbf)) {
                $str = substr($str, 3);
            }
            return $str;
        }

        public function isCommandLineInterface()
        {
            return (php_sapi_name() === 'cli');
        }

        private function browser_get_contents($url)
        {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
            curl_setopt($ch, CURLOPT_REFERER, $this->referer);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->curlConnectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->curlTimeout);
            $html = curl_exec($ch);
            $info_arr = curl_getinfo($ch);
            //print_r($info_arr);
            if ($info_arr['redirect_url'])
                $html = $info_arr['redirect_url'];

            curl_close($ch);
            return $html;
        }

        private function browser_post_contents($url, $param)
        {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $param);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
            curl_setopt($ch, CURLOPT_REFERER, $this->referer);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->curlConnectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->curlTimeout);
            //echo $this->pre(curl_getinfo($ch));die();
            //echo $this->pre($param);die();
            $html = curl_exec($ch);
            //echo $this->pre($html);die();
            $info_arr = curl_getinfo($ch);
            //echo $this->pre($info_arr);die();
            if ($info_arr['redirect_url'])
                $html = $info_arr['redirect_url'];

            curl_close($ch);
            return $html;
        }

        private function parse_form($page_cont = null)
        {
            $param_arr = array();
            if (isset($page_cont) and !empty($page_cont))
            {
                $page_cont = str_replace("\r" , "", $page_cont);
                $page_cont = str_replace("\n" , "", $page_cont);
                //print_r($page_cont);

                preg_match_all("/<FORM(.*?)<\/FORM>/i", $page_cont, $matchForm);
                //print_r($matchForm);
                //$page_cont = $matchForm[0][0];

                preg_match_all("/<INPUT(.*?)>/i", $page_cont, $matchInput);
                //echo $this->pre($matchInput[1]);die();

                foreach($matchInput[1] as $key => $value)
                {
                    preg_match_all("/NAME=\"(.*?)\"/i", $value, $matchName);
                    //echo $this->pre($matchName[1][0]);die();

                    preg_match_all("/VALUE=\"(.*?)\"/i", $value, $matchValue);
                    //echo $this->pre($matchValue[1][0]);die();

                    if(isset($matchName[1][0]) and !empty($matchName[1][0]))
                        $param_arr[$matchName[1][0]] = (isset($matchValue[1][0]) and !empty($matchValue[1][0])) ? $matchValue[1][0] : '';
                }
                //$param_arr = array_filter($param_arr);
                unset($param_arr['']);
                //print_r($param_arr);
            }
            return $param_arr;
        }

        public function getDomainBalance()
        {
            $domain = $this->login;
            $domain = strtolower(trim($domain));
            $domain = preg_replace('/^http(.*):\/\//i', '', $domain);
            $domain = preg_replace('/^www\./i', '', $domain);
            $domain = explode('/', $domain);
            $domain = trim($domain[0]);

            // split the TLD from domain name
            $_domain = explode('.', $domain);
            $lst = count($_domain)-1;
            $ext = $_domain[$lst];

            $servers = array(
                "biz" => "whois.neulevel.biz",
                "com" => "whois.internic.net",
                "us" => "whois.nic.us",
                "coop" => "whois.nic.coop",
                "info" => "whois.nic.info",
                "name" => "whois.nic.name",
                "net" => "whois.internic.net",
                "gov" => "whois.nic.gov",
                "edu" => "whois.internic.net",
                "mil" => "rs.internic.net",
                "int" => "whois.iana.org",
                "ac" => "whois.nic.ac",
                "ae" => "whois.uaenic.ae",
                "at" => "whois.ripe.net",
                "au" => "whois.aunic.net",
                "be" => "whois.dns.be",
                "bg" => "whois.ripe.net",
                "br" => "whois.registro.br",
                "bz" => "whois.belizenic.bz",
                "ca" => "whois.cira.ca",
                "cc" => "whois.nic.cc",
                "ch" => "whois.nic.ch",
                "cl" => "whois.nic.cl",
                "cn" => "whois.cnnic.net.cn",
                "cz" => "whois.nic.cz",
                "de" => "whois.nic.de",
                "fr" => "whois.nic.fr",
                "hu" => "whois.nic.hu",
                "ie" => "whois.domainregistry.ie",
                "il" => "whois.isoc.org.il",
                "in" => "whois.ncst.ernet.in",
                "ir" => "whois.nic.ir",
                "mc" => "whois.ripe.net",
                "to" => "whois.tonic.to",
                "tv" => "whois.tv",
                "ru" => "whois.nic.ru",//"whois.ripn.net",
                "рф" => "whois.nic.ru",//"whois.ripn.net",
                "org" => "whois.pir.org",
                "aero" => "whois.information.aero",
                "nl" => "whois.domain-registry.nl"
            );

            if(!isset($servers[$ext])) die('-1'); //Error: No matching nic server found!

            $nic_server = $servers[$ext];
            $output = $date = '';

            // connect to whois server:
            if($conn = fsockopen ($nic_server, 43))
            {
                fputs($conn, $domain."\r\n");
                while(!feof($conn)) $output .= fgets($conn,128);
                fclose($conn);
                
                preg_match('/paid-till:(.*)/i', $output, $match); // ru|net.ru|org.ru|pp.ru|рф
                if(!isset($match[1]) or empty($match[1]))
                {
                    preg_match('/Registry Expiry Date:(.*)/i', $output, $match); // org|com|net - 2021-05-03T04:00:00Z
                    if(isset($match[1]) and !empty($match[1]))
                    {
                        $match = explode('T',$match[1]); //$match[0]=2021-05-03 ; $match[1]=04:00:00Z
                        $date = trim($match[0]);
                    }
                }
                else
                {
                    $date = trim($match[1]);
                }
                $date = str_replace('.','-',$date);
                $output = date_diff(new DateTime(), new DateTime($date))->days;
            }
            else
            {
                $date = date('Y-m-d');
                $output='-1';//Error: Could not connect to '.$nic_server.'!')
            }

            echo $output.' ('.$date.')';
        }

        /**
         * https://locum.ru/
         */
        /*
        public function getLocumBalance()
        {
            $page_cont = '';
            for ($i = 0; $i <= 3; $i++)
            {
                if (!$page_cont)
                {
                    $url = "https://locum.ru/";
                    $page_cont = $this->browser_get_contents($url);
                    //echo pre($page_cont);die();
                    $param_arr = array();
                    $param_arr = $this->parse_form($page_cont);
                    //echo $this->pre($param_arr);die();

                    $param_arr['login_data[login]'] = $this->login;
                    $param_arr['login_data[password]'] = $this->password;
                    //print_r($param_arr);

                    $param = '';
                    $url = "https://locum.ru/entrance";
                    $param = http_build_query($param_arr, '', '&');
                    //echo $this->pre($param);die();

                    $page_cont = $this->browser_post_contents($url, $param);
                    //echo $this->pre($page_cont);die();

                    $page_cont = $this->browser_get_contents($page_cont); //https://locum.ru/user/show
                    //echo $this->pre($page_cont);die();

                    preg_match('/<div class="value"><span class="number">(.*)<\/span><span class="sign">руб.<\/span><\/div>/i', $page_cont, $match);
                    $balance = preg_replace('/[^0-9.]/', '', $match[1]);

                    if(isset($balance) and !empty($balance)) break;
                }
            }

            return $balance;
        }
        */

        public function getMosenergosbytBalance()
        {
            $this->referer = 'https://my.mosenergosbyt.ru/auth';
            $this->userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/84.0.4147.135 Safari/537.36';
            $this->headers = array(
                    'Sec-Fetch-Dest: empty',
                    'Sec-Fetch-Mode: cors',
                    'Sec-Fetch-Site: same-origin'
                );

            $page_cont = '';
            $balance = 0;
            //делаем до 3 запросов, т.к. сайт иногда не отвечает с первого раза
            for ($i = 0; $i <= 3; $i++)
            {
                if (!$page_cont)
                {
                    $param           = array();
                    $param['login']  = $this->login;
                    $param['psw']    = $this->password;
                    $param['action'] = 'auth';
                    $param['query']  = 'login';
                    $param           = http_build_query($param);
                    $url             = "https://my.mosenergosbyt.ru/gate_lkcomu";
                    $page_cont       = $this->browser_post_contents($url, $param);
                    //echo $this->pre($page_cont);die();
                    $page_cont       = json_decode($page_cont, true);
                    //echo $this->pre($page_cont);die();
                    if($page_cont['data'][0]['kd_result']!=0) die('auth failed');

                    $session          = $page_cont['data'][0]['session'];

                    $param            = array();
                    $param['action']  = 'sql';
                    $param['query']   = 'LSList';
                    $param['session'] = $session;
                    $param            = http_build_query($param);
                    $url              = "https://my.mosenergosbyt.ru/gate_lkcomu";
                    $page_cont        = $this->browser_post_contents($url, $param);
                    $page_cont        = json_decode($page_cont, true);
                    //echo $this->pre($page_cont);die();

                    $dt_st = date('Y').'-'.(date('m')-1).'-01T00:00:00';
                    $dt_en = date('Y').'-'.date('m').'-'.date('t').'T23:59:59';

                    $param = array();
                    $param['action']      = 'sql';
                    $param['query']       = 'bytProxy';
                    $param['plugin']      = 'bytProxy';
                    $param['proxyquery']  = 'Invoice';
                    $param['session']     = $session;
                    $param['dt_en']       = $dt_en;
                    $param['dt_st']       = $dt_st;
                    $param['vl_provider'] = $page_cont['data'][0]['vl_provider'];
                    //echo $this->pre($param);die();
                    $param                = http_build_query($param);
                    $url                  = "https://my.mosenergosbyt.ru/gate_lkcomu";
                    $page_cont            = $this->browser_post_contents($url, $param);
                    $page_cont            = json_decode($page_cont, true);
                    //echo $this->pre($page_cont);die();
                    //echo $this->pre($page_cont['data'][0]['data_common']);die();
                    foreach($page_cont['data'][0]['data_common'] as $k=>$v)
                    {
                        if($v['nm_value']=='Итого к оплате')
                        {
                            $balance = $v['vl_value'];
                            break;
                        }
                    }
                    //if(isCommandLineInterface()) echo "\n...\n";
                }
            }

            return $balance;
        }

        public function getBeelineBalance()
        {
            $page_cont = '';
            //делаем до 3 запросов, т.к. сайт иногда не отвечает с первого раза
            for ($i = 0; $i <= 3; $i++)
            {
                if (!$page_cont)
                {
                    $param = array();
                    $param['CTN'] = $this->login;
                    $param = http_build_query($param);
                    $url = "https://moskva.beeline.ru/menu/loginmodel/?".$param;
                    $page_cont = $this->browser_get_contents($url); //
                    //echo $this->pre($page_cont);die();
                    $page_cont = json_decode($this->removeBOM($page_cont), true);
                    //echo $this->pre($page_cont);die();
                    //if(isCommandLineInterface()) echo "\n...\n";

                    $param = array();
                    $param['login']         = $this->login;
                    $param['password']      = $this->password;
                    $param['client_id']     = $page_cont['clientId'];
                    $param['redirect_uri']  = $page_cont['returnUrl'];
                    $param['response_type'] = 'id_token';
                    $param['response_mode'] = 'form_post';
                    $param['state']         = $page_cont['state'];
                    $param['scope']         = $page_cont['requestScope'];
                    $param['nonce']         = $this->removeBOM($page_cont['nonce']);
                    $param['remember_me']   = true;
                    $url = "https://identity.beeline.ru/identity/fpcc";
                    $page_cont = $this->browser_post_contents($url, $param);
                    //echo $this->pre($page_cont);die();
                    //if(isCommandLineInterface()) echo "\n...\n";

                    $page_cont = $this->browser_get_contents($page_cont);// https://identity.beeline.ru/identity/connect/authorize?scope=
                    //echo $this->pre($page_cont);die();
                    //if(isCommandLineInterface()) echo "\n...\n";

                    $page_cont = $this->browser_get_contents($page_cont); // https://identity.beeline.ru/identity/login?signin=
                    //echo pre($page_cont);
                    //if(isCommandLineInterface()) echo "\n...\n";

                    $page_cont = $this->browser_get_contents($page_cont); // https://identity.beeline.ru/identity/resumeauth
                    //echo $this->pre($page_cont);die();
                    //if(isCommandLineInterface()) echo "\n...\n";

                    $page_cont = $this->browser_get_contents($page_cont); // https://identity.beeline.ru/identity/return?resume=
                    //echo $this->pre($page_cont);die();
                    //if(isCommandLineInterface()) echo "\n...\n";

                    $page_cont = $this->browser_get_contents($page_cont); // https://identity.beeline.ru/identity/connect/authorize?scope=
                    //echo $this->pre($page_cont);die();
                    $page_cont = $this->parse_form($page_cont);
                    //echo pre($page_cont);die();
                    //if(isCommandLineInterface()) echo "\n...\n";

                    $url = "https://moskva.beeline.ru/regionlogincallback/";
                    $page_cont = $this->browser_post_contents($url, $page_cont);
                    //echo pre($page_cont);die();

                    $url = "https://moskva.beeline.ru/api/profile/userinfo/data/?noTimeout=false&blocks=Balance,Status";
                    $page_cont = $this->browser_get_contents($url); //
                    $page_cont = json_decode($this->removeBOM($page_cont), true);
                    //echo $this->pre($page_cont);die();

                    if(isset($page_cont['balance']['data']['balance']) and !empty($page_cont['balance']['data']['balance'])) break;
                }
            }

            return $page_cont['balance']['data']['balance'];
        }

        public function getSevenskyBalance()
        {
            $page_cont = '';
            for ($i = 0; $i <= 3; $i++)
            {
                if (!$page_cont)
                {
                    $url = "https://lk.seven-sky.net/";
                    $page_cont = $this->browser_get_contents($url);
                    //echo pre($page_cont);die();
                    $param_arr = array();
                    $param_arr = $this->parse_form($page_cont);
                    //echo pre($param_arr);die();

                    $param_arr['login'] = $this->login;
                    $param_arr['password'] = $this->password;
                    //print_r($param_arr);

                    $param = '';
                    $url = "https://lk.seven-sky.net/ajax/login.jsp";
                    $param = http_build_query($param_arr, '', '&');
                    //echo pre($param);die();

                    $page_cont = $this->browser_post_contents($url, $param);
                    //echo pre($page_cont);die();
                    $page_cont = json_decode($page_cont,true);
                    if(!isset($page_cont['res']) or $page_cont['res']!=1) die('auth failed');
                    //echo pre($page_cont);die();

                    $url = "https://lk.seven-sky.net/index.jsp";
                    $page_cont = $this->browser_get_contents($url);
                    //echo pre($page_cont);die();

                    preg_match('/<li>Баланс:<br \/><span>(.*)<\/span><span>руб.<\/span><\/li>/i', $page_cont, $match);
                    $balance = preg_replace('/[^0-9.]/', '', $match[1]);

                    //preg_match('/<li id="block-period">Дней до блокировки:<span style="display: block; text-align: center;">(.*)<\/span>/i', $page_cont, $match);
                    //$days = preg_replace('/[^0-9]/', '', $match[1]);
                    //echo '<pre>'.print_r($res,true).'</pre>';die();

                    if(isset($balance) and !empty($balance)) break;
                }
            }

            return $balance;
        }

        public function getMangoBalance()
        {
            $page_cont = '';

            //делаем до 3 запросов, т.к. сайт иногда не отвечает с первого раза
            for ($i = 0; $i <= 3; $i++)
            {
                if (!$page_cont)
                {
                    $url = "https://lk.mango-office.ru/";
                    $page_cont = $this->browser_get_contents($url);
                    //print_r($page_cont);die();
                    $param_arr = array();
                    $param_arr = $this->parse_form($page_cont);
                    //print_r($param_arr);die();

                    $param_arr['username'] = $this->login;
                    $param_arr['password'] = $this->password;
                    $param_arr['app']      = 'ics';
                    $param_arr['startSession'] = '1';
                    //print_r($param_arr);

                    $url = "https://auth.mango-office.ru/auth/vpbx/";
                    $param = http_build_query($param_arr, '', '&');
                    //print_r($param);die();

                    $page_cont = $this->browser_post_contents($url, $param);
                    //print_r($page_cont);die();
                    $param_arr = json_decode($page_cont,true);
                    //print_r($param_arr);die();

                    if(!isset($param_arr['result']) or (int)$param_arr['result']!=1000) die('auth failed');

                    $param_arr['username'] = $this->login;
                    $param = http_build_query($param_arr, '', '&');
                    //print_r($param);die();

                    $auth_token = $param_arr['auth_token'];

                    $url = "https://lk.mango-office.ru/auth/create-session";
                    $page_cont = $this->browser_post_contents($url, $param);
                    //print_r($page_cont);die();
                    $param_arr = json_decode($page_cont,true);
                    if(!isset($param_arr['result']) or (int)$param_arr['result']!=1000) die('auth failed');
                    //print_r($param_arr);die();

                    $url = "https://lk.mango-office.ru/";
                    $page_cont = $this->browser_get_contents($url); // $page_cont = https://lk.mango-office.ru/400019509/400038263/
                    //print_r($page_cont);die();
                    preg_match('/lk.mango-office.ru\/(.*)\/(.*)\//', $page_cont, $match);
                    //print_r($match);die();
                    $prod_id = $match[2];

                    $page_cont = $this->browser_get_contents($page_cont); // $page_cont = https://lk.mango-office.ru/400019509/400038263/product
                    //print_r($page_cont);die();

                    $page_cont = $this->browser_get_contents($page_cont); // $page_cont = https://lk.mango-office.ru/profile/400019509/400038263
                    //print_r($page_cont);die();

                    $page_cont = $this->browser_get_contents($page_cont); //
                    //print_r($page_cont);die();

                    $param = array();
                    $url="https://api-header.mango-office.ru/api/account";
                    $param['auth_token'] = $auth_token;
                    $param['isFirstEntry'] = '';
                    $param['locale']= 'ru';
                    $param['prod_code']= '';
                    $param['prod_id']= $prod_id;
                    $page_cont = $this->browser_post_contents($url, $param); //
                    $res = json_decode($page_cont,true);
                    //echo '<pre>'.print_r($res,true).'</pre>';die();

                    if(isset($res['data']['balance']) and !empty($res['data']['balance'])) break;
                }
            }

            return $res['data']['balance'];
        }

        public function getTele2Balance()
        {
            $this->login = '7'.ltrim($this->login, $this->login[0]);
            $this->headers = array(
                                   'Connection: keep-alive',
	                               'Tele2-User-Agent: "mytele2-app/3.17.0"; "unknown"; "Android/9"; "Build/12998710"',
	                               'X-API-Version: 1',
	                               'User-Agent: okhttp/4.2.0'
                                    );
            $page_cont = '';

            //делаем до 3 запросов, т.к. сайт иногда не отвечает с первого раза
            for ($i = 0; $i <= 3; $i++)
            {
                if (!$page_cont)
                {
                    $param = array();
                    $param['username']      = $this->login;
                    $param['password']      = $this->password;
                    $param['grant_type']    = 'password';
                    $param['client_id']     = 'android-app';
                    $param['password_type'] = 'password';
                    
                    $url = "https://my.tele2.ru/auth/realms/tele2-b2c/protocol/openid-connect/token";
                    $param = http_build_query($param, '', '&');
                    //echo $this->pre($param);die();
                    $page_cont = $this->browser_post_contents($url, $param);
                    //echo $this->pre($page_cont);die();
                    $page_cont = json_decode($page_cont,true);
                    if(!isset($page_cont['access_token']) or empty($page_cont['access_token'])) die('auth failed');
                    
                    $this->headers = array(
                                           'Connection: keep-alive',
        	                               'Tele2-User-Agent: "mytele2-app/3.17.0"; "unknown"; "Android/9"; "Build/12998710"',
        	                               'X-API-Version: 1',
        	                               'User-Agent: okhttp/4.2.0',
                                           'Authorization: Bearer '.$page_cont['access_token'],
                                            );

                    $url = "https://my.tele2.ru/api/subscribers/".$this->login."/balance";
                    $page_cont = $this->browser_get_contents($url);
                    //echo $this->pre($page_cont);die();
                    $page_cont = json_decode($page_cont,true);
                    //echo $this->pre($page_cont);die();
                    if(!isset($page_cont['data']) or empty($page_cont['data'])) die('auth failed');

                    if(isset($page_cont['data']['value']) and !empty($page_cont['data']['value'])) break;
                }
            }

            return $page_cont['data']['value'];
        }

        /*
        public function getZadarmaBalance()
        {
            $page_cont = '';

            //делаем до 3 запросов, т.к. сайт иногда не отвечает с первого раза
            for ($i = 0; $i <= 3; $i++)
            {
                if (!$page_cont)
                {
                    $url = "https://my.zadarma.com/auth/";
                    $page_cont = $this->browser_get_contents($url);
                    //print_r($page_cont);die();
                    $param_arr = array();
                    $param_arr = $this->parse_form($page_cont);
                    //print_r($param_arr);die();

                    $param_arr['email'] = $this->login;
                    $param_arr['password'] = $this->password;
                    //print_r($param_arr);

                    $url = "https://my.zadarma.com/auth/login/";
                    $param = http_build_query($param_arr, '', '&');
                    print_r($param);die();

                    $page_cont = $this->browser_post_contents($url, $param);
                    print_r($page_cont);die();
                    $param_arr = json_decode($page_cont,true);
                    //print_r($param_arr);die();
                }
            }

            return '-1';
        }
        */

        protected function yandexAuth()
        {
            $page_cont = '';
            //делаем до 3 запросов, т.к. сайт иногда не отвечает с первого раза
            for ($i = 0; $i <= 3; $i++)
            {
                if (!$page_cont)
                {
                    $url = "https://passport.yandex.ru/auth?";
                    $page_cont = $this->browser_get_contents($url);
                    //print_r($page_cont);die();
                    $param_arr = $this->parse_form($page_cont);
                    //print_r($param_arr);die();

                    $param_arr['login'] = $this->login;
                    $param_arr['hidden-password'] = $this->password;
                    //print_r($param_arr);

                    $url = "https://passport.yandex.ru/auth?retpath=https%3A%2F%2Fdirect.yandex.ru%2F?";
                    $param = http_build_query($param_arr, '', '&');
                    //print_r($param);die();

                    $page_cont = $this->browser_post_contents($url, $param);
                    //print_r($page_cont);die();

                    $param_arr = $this->parse_form($page_cont);
                    //print_r($param_arr);
                    $param_arr['login'] = $this->login;
                    $param_arr['passwd'] = $this->password;
                    //print_r($param_arr);

                    $url = "https://passport.yandex.ru/auth?retpath=https%3A%2F%2Fdirect.yandex.ru%2F?";
                    $param = http_build_query($param_arr, '', '&');
                    $page_cont = $this->browser_post_contents($url, $param); // $page_cont = https://passport.yandex.ru/auth/finish?track_id=afa728f21d848cd4699bb3d878652ae71d
                    //print_r($page_cont);die();

                    $page_cont = $this->browser_get_contents($page_cont); // $page_cont = https://passport.yandex.ru/passport?mode=passport
                    //print_r($page_cont);die();

                    $page_cont = $this->browser_get_contents($page_cont); // $page_cont = https://passport.yandex.ru/profile
                    //print_r($page_cont);die();

                    if($page_cont) break;
                }
            }
            return $page_cont;
        }

        public function getYandexdirectBalance()
        {
            $page_cont = $this->yandexAuth();
            //print_r($page_cont);die();
            //$url = "https://direct.yandex.ru/registered/main.pl?cmd=showCamps";
            //$page_cont = $this->browser_get_contents($url);
            //preg_match('/<div class="b-wallet-link__title-sum b-wallet-link__valign-middle">(.*?)<\/div>/', $page_cont, $match);
            // 19.10.2020 - https://yandex.ru/adv/news/novyy-dizayn-spiska-kampaniy-v-direkte-dlya-vsekh
            $url = "https://direct.yandex.ru/registered/main.pl?ulogin=".$this->login."&cmd=clientWallet";
            $page_cont = $this->browser_get_contents($url);
            preg_match('/<div class="b-client-wallet__remain-without-nds">(.*?)<\/div>/', $page_cont, $match);
            // 19.10.2020            
            $res = $match[1];
            $res = str_replace("руб." , "", $res);
            $res = trim(preg_replace('/[[:^print:]]/', '', $res));
            return $res;
        }

        public function getYandexmarketBalance()
        {
            $page_cont = $this->yandexAuth();
            $url = "https://partner.market.yandex.ru/?list=yes";
            $page_cont = $this->browser_get_contents($url);
            preg_match_all('/"daysLeftToSpend":"(.*?)","actualBalance":"(.*?)"/', $page_cont, $match);
            return $match[2][0].'('.$match[1][0].')';
        }

    } // end of class



    $balances = new balances();
    //echo $balances->cookieFile;
    if ($balances->isCommandLineInterface())
    {
        if(!isset($argv[1]) or empty($argv[1]))
            die("Usage: php blalances.php provider login password\nExample: php balances.php beeline 9061234567 test\n\n");

        $balances->provider = $argv[1];
        $balances->login    = $argv[2];
        $balances->password = (isset($argv[3]) and !empty($argv[3])) ? $argv[3] : "";
    }
    else
    {
        $balances->provider = $_GET['mode'];
        $balances->login    = $_GET['login'];
        $balances->password = (isset($_GET['password']) and !empty($_GET['password'])) ? $_GET['password'] : "";
    }

    $func = 'get'.ucfirst($balances->provider).'Balance';
    if(method_exists($balances,$func))
        echo $balances->$func();
    else
        echo 'Method "'.$func.'()" not exists';
