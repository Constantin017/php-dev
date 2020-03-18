<?php
/**
 * 1. По ссылке скачать html контент
 * 2. Найти ссылки страницы
 * 3. Найти картинки, регулярное выражение ("/<img /")
 * 4. Пройтись по ссылкам найденным в 2 и посчитать 3 
 * 5. Метрика операции подсчета картинок время
 * 6. Результат в таблицу
 */
$error_cli = "ERROR: Need to be in CLI mode only.".PHP_EOL;
$error_uri = "ERROR: First argument need to be a valid link to web page.".PHP_EOL;
// Проверка на то что мы в режиме CLI а не запрос от вебсервера
$cli = (php_sapi_name() === 'cli') ? true : exit($error_cli);
// Проверка на то что передали 1 аргументом валидную ссылку
$uri = (!empty($argv[1])) ? $argv[1] : exit($error_uri);
// Разбираем ссылку на составляющие
$parse_url = parse_url($argv[1]);
if ( !empty($parse_url) && is_array($parse_url) && ($parse_url["scheme"] === '' || $parse_url["host"] === '')) {
    exit($error_uri);
}
// Отключения времени
// set_time_limit(0);

var_dump(gethostbyname($uri));

$crawler = new Crawler();
$crawler->process($uri);
sleep(1);
var_dump([
    $crawler->getFileName(),
    $crawler->getData()
]);

class Crawler
{
    protected $data;
    protected $file_name;
  
    public function __construct()
    {
        $this->data = [];
        $this->file_name = 'report_'.date('d.m.Y', time()).'.html';
    }

    public function process(string $uri)
    {
        $data = [];

        $_data = $this->_grab($uri);
        
        $data[] = $_data;

        if (!empty($_data['links']) && false) {
            foreach ($_data['links'] as $link) {
                $data[] = $this->_grab($link);
            }
        }

        $this->data = $data;
        unset($data);
    }

    public function getData()
    {
        return $this->data;
    }

    public function getFileName()
    {
        return $this->file_name;
    }

    public function setFileName(string $file_name = '')
    {
        if (!empty($file_name)) {
            $this->file_name = $file_name;
        }
        
        return $this;
    }

    public function saveReport()
    {
        $file_path = __DIR__.$this->file_name;
        $file_data = $this->data;

        return file_put_contents($file_path, $file_data);
    }

    private function _grab(string $uri) {
        $report = [
            'links' => [],
            'images'=> 0,
            'time'  => 0
        ];
        $time = time();
    
        // Примитивно, без передачи заголовков. Игнорируем ошибку результата запроса.
        $content = $this->_getRequest($uri);

        if ($content === '') {
            return $report;
        } 
        
        $parsed = $this->_domParse($content);
    
        // Вычитаем из текущего времени время которое сохранили ранее, получаем время работы функции
        $time = time() - $time;
    
        $report['links']    = $parsed['links'];
        $report['images']   = $parsed['images'];
        $report['time']     = $time;
        
        return $report;
    }

    private function _getRequest(string $uri)
    {
        $fp = fsockopen($uri, 80, $errno, $errstr, 30);
        
        if (!$fp) {
            throw new Exception("ERROR: $errstr ($errno)", 1);
        } else {
            $header = "GET / HTTP/1.1\r\n";
            $header .= "Host: www.example.com\r\n";
            $header .= "Connection: Close\r\n\r\n";
        
            fwrite($fp, $header);
        
            while (!feof($fp)) {
                $content .= fgets($fp, 128);
            }
            fclose($fp);
            unset($header);
        }

        return $content; 
    }
    
    private function _domParse(string $html)
    {
        $hrefs = [];

        $doc = new DOMDocument();
        $doc->loadHTML($html);
        
        $xml = simplexml_import_dom($doc);
        
        $images = $xml->xpath('//img');
        
        $links = $xml->xpath('//a');

        foreach ($links as $link)
        {
            $hrefs[] = $link['href'];
        }
        
        $_return = [
            'images'=> count($images),
            'links' => $hrefs
        ];

        unset($doc, $xml, $images, $links, $hrefs);
        
        return $_return;
    }

}