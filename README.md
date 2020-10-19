Проверка баланса для Zabbix 5.0.2

Последняя проверка работоспособности скрипта была проведена 19.10.2020

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
