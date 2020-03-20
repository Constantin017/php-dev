<?php
/**
 * 1. По ссылке скачать html контент
 * 2. Найти ссылки страницы
 * 3. Найти картинки, регулярное выражение ("/<img /")
 * 4. Пройтись по ссылкам найденным в 2 и посчитать 3 
 * 5. Метрика операции подсчета картинок время
 * 6. Результат в таблицу
 * 
 * DDD стилем не пахнет.
 */

// ------------------- ERRORS AND SETTINGS -------------------
$ERRORS=[
    0 => 'ERROR: Need to be in CLI mode only.'.PHP_EOL,
    1 => 'ERROR: First argument need to be a valid link to web page.'.PHP_EOL,
];    
// Проверка на то что мы в режиме CLI а не запрос от вебсервера
$cli = (php_sapi_name() === 'cli') ? true : exit($ERRORS[0]);
// Проверка на то что передали 1 аргументом валидную ссылку
$uri = (!empty($argv[1]) && filter_var($argv[1], FILTER_VALIDATE_URL)) ? $argv[1] : exit($ERRORS[1]);

// Отключения времени
set_time_limit(0);
// Игнорируем ошибки уровня PHP Warning. DOMDocument и simplexml_import_dom не понимает html5 и svg теги (header...)
error_reporting(~E_WARNING);

// ------------------- EXECUTION -------------------

$crawler = new Crawler();
$crawler->process($uri);
$crawler->saveReport();

// ------------------- SPACE ECONOMY -------------------
/**
 * Class Crawler
 * 
 * @author Constantin017 <konstantin4all@gmail.com>
 * 
 */
class Crawler
{
    /**
     * Хранение сырых отчётных данных
     * 
     * @var array $data
     */
    protected $data;
    
    /**
     * Строка хранящяя в себе название файла
     * 
     * @var string $file_name
     */
    protected $file_name;
    
    /**
     * Строка хранящяя в себе стартовую ссылку по которой происходит анализ страниц
     * 
     * @var string $cannonical_uri
     */
    protected $cannonical_uri;

    /**
     * Функция инициализатор класса
     * 
     * @return self
     */
    public function __construct()
    {
        $this->data = [];
        $this->file_name = 'report_'.date('d.m.Y', time()).'.html';
    }

    /**
     * Скармливаем нашему экземпляру ссылку
     * 
     * @param string $uri 
     * 
     * @return void
     */
    public function process(string $uri)
    {
        $data = [];

        $this->_setCannonical($uri);
        $_data = $this->_grab($uri);

        $data[] = $_data;

        if (!empty($_data['links'])) {
            foreach ($_data['links'] as $link) {
                $link = $this->_onlyCannonicalURI($link);
                if ($link !== '' && filter_var($link, FILTER_VALIDATE_URL)) {
                    $data[] = $this->_grab($link);
                }
            }
        }

        $this->data = $data;
        unset($data);
    }

    /**
     * Получаем сырые отчётные данные в виде массива
     * 
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Получаем наименование файла отчета
     * 
     * @param staring $file_name 
     * 
     * @return self
     */
    public function getFileName()
    {
        return $this->file_name;
    }

    /**
     * Устанавливаем наименование файла отчета
     * 
     * @param staring $file_name 
     * 
     * @return self
     */
    public function setFileName(string $file_name = '')
    {
        if (!empty($file_name)) {
            $this->file_name = $file_name;
        }
        
        return $this;
    }

    /**
     * Получаем путь к папке с именем отчетного файла
     * 
     * @return string
     */
    public function getFullPath()
    {
        return __DIR__.'/'.$this->file_name;
    }

    /**
     * Сохраняем отчет в файле заданной структуры в виде таблицы
     * 
     * @return void
     */
    public function saveReport()
    {
        $f = fopen($this->getFullPath(), 'wb');

        fwrite($f, '<table>'.PHP_EOL);

        $this->_prepareDataForExportReport();

        if (!empty($this->data)) {
            foreach ($this->data as $row) {
                fwrite($f, '<tr>'.PHP_EOL);
                foreach ($row as $key => $value) {
                    if ($key == 'links') {
                        continue;
                    }
                    fwrite($f, '<td>'.$value.'</td>');
                }
                fwrite($f, PHP_EOL.'</tr>'.PHP_EOL);
            }
        }
        fwrite($f, '</table>');
        
        fclose($f);

    }

    /**
     * Получаем массив отчет по ссылке на страницу
     * 
     * @param staring $uri 
     * 
     * @return array
     */
    private function _grab(string $uri) {
        $report = [
            'uri'   => $uri,
            'links' => [],
            'images'=> 0,
            'time'  => 0
        ];
        $time_start = time();
    
        // Примитивно, без передачи заголовков. Игнорируем ошибку результата запроса.
        $content = $this->_getRequest($uri);

        if ($content === '') {
            return $report;
        } 
        
        $parsed = $this->_domParse($content);
    
        $report['links']    = $parsed['links'];
        $report['images']   = $parsed['images'];

        // Вычитаем из текущего времени время которое сохранили ранее, получаем время работы функции
        $report['time']     = time() - $time_start;
        
        return $report;
    }

    /**
     * Получаем контент страницы по ссылке
     * 
     * @return string
     */
    private function _getRequest(string $uri)
    {
        return (filter_var($uri, FILTER_VALIDATE_URL) !== false) ? file_get_contents($uri) : ''; 
    }
    
    /**
     * Получаем массив из количества картинок и ссылок на странице
     * 
     * @param staring $html 
     * 
     * @return array
     */
    private function _domParse(string $html)
    {
        $_return = [
            'images'=> 0,
            'links' => []
        ];

        $doc = new DOMDocument();
        $doc->loadHTML($html);
        
        $xml = simplexml_import_dom($doc);
        
        if ($xml === false) {
            return $_return;
        }

        $images = $xml->xpath('//img');
        // Проверяем что картинки были найдены, и если да, то преобразуем в количество
        $images = ($images !== false) ? count($images) : 0;

        $links = $xml->xpath('//a');

        // Проверяем что ссылки были найдены
        if ($links !== false) {
            foreach ($links as $link) {
                $_uri = strval($link['href']);
                if ($_uri !== '' && filter_var($_uri, FILTER_VALIDATE_URL) !== false) {
                    $_return['links'][] = $_uri;    
                }
            }
        }
        
        $_return['links'] = array_unique($_return['links']);
        $_return['images'] = $images;

        unset($doc, $xml, $images, $links);
        
        return $_return;
    }

    /**
     * Сортируем данные в нужном поряке согласно заданий по количеству изображений
     * 
     * @return void
     */
    private function _prepareDataForExportReport()
    {
        foreach ($this->data as $row => $item) {
            $images[$row]  = $item['images'];
        }

        array_multisort($images, SORT_DESC, $this->data);
    }

    /**
     * Сравниваем ссылки, получаем ссылки относящиеся к нашей канонической ссылки
     * 
     * @param string $uri 
     * 
     * @return string
     */
    private function _onlyCannonicalURI(string $uri)
    {
        $parse_home = parse_url($this->cannonical_uri);
        $parse_uri  = parse_url($uri);

        $return_uri = '';
        if (isset($parse_uri['host']) && $parse_uri['host'] !== $parse_home['host']) {
            return $return_uri;
        }

        if (rtrim($this->cannonical_uri,'/') === rtrim($uri,'/')) {
            return $return_uri;
        }

        $return_uri .= $parse_home['host'];

        $return_uri .= isset($parse_home['user']) ? $parse_home['user'] : '';
        $return_uri .= isset($parse_home['pass']) ? ':' . $parse_home['pass']  : '';
        $return_uri .= (isset($parse_home['user']) || isset($parse_home['pass'])) ? "@" : '';

        $return_uri .= isset($parse_uri['path']) ? '/'.$parse_uri['path'] : '';
        $return_uri .= isset($parse_uri['query']) ? '?' . $parse_uri['query'] : '';
        $return_uri .= isset($parse_uri['fragment']) ? '#' . $parse_uri['fragment'] : '';

        return $parse_home['scheme'].'://'.str_replace('//', '/', $return_uri);
    }

    /**
     * Устанавливаем канноническую ссылку, ее будем сравнивать с ссылками на странице
     * 
     * @return self 
     */
    private function _setCannonical(string $uri)
    {
        $this->cannonical_uri = $uri;

        return $this;
    }

}