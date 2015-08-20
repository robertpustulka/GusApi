<?php
namespace GusApi;

use Curl\Curl;
use GusApi\Exception\InvalidTypeException;
use GusApi\Exception\InvalidUserKeyException;
use GusApi\Exception\CurlException;
use GusApi\Exception\NotFoundException;
use GusApi\ReportType;

/**
 * Class GusApi
 * @package GusApi
 * @author Janusz Żukowicz <john_zuk@wp.pl>
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
class GusApi
{
    private $userKey = "aaaaaabbbbbcccccdddd";

    const URL_BASIC = "https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc/ajaxEndpoint/";

    const URL_LOGIN = "Zaloguj";

    const URL_GET_CAPTCHA = "PobierzCaptcha";

    const URL_CHECK_CAPTCHA = "SprawdzCaptcha";

    const URL_SEARCH = "daneSzukaj";

    const URL_FULL_REPORT = "DanePobierzPelnyRaport";

    const BASIC_HEADER_PARAMETER = 'pParametryWyszukiwania';

    /**
     * @var Curl
     */
    private $curl;

    public function __construct($userKey)
    {
        $this->curl = new Curl();
        $this->curl->setHeader('Content-Type', 'application/json');
        $this->userKey = $userKey;
    }

    public function __destruct()
    {
        $this->curl->close();
    }

    /**
     * Login in to regin server
     *
     * @return string session id
     * @throws CurlException
     */
    public function login()
    {
        $this->preparePostData(self::URL_LOGIN, ["pKluczUzytkownika" => $this->userKey]);
        $sid = $this->getResponse();

        if (empty($sid)) {
            throw new InvalidUserKeyException("Invalid user key!");
        }

        return $sid;
    }

    /**
     * Get captcha base64 encoding image
     *
     * @param string $sid
     * @return string base64 encoding image
     * @throws CurlException
     */
    public function getCaptcha($sid)
    {
        $this->preparePostData(self::URL_GET_CAPTCHA, [], $sid);
        return $this->getResponse();
    }

    /**
     * Check captcha
     *
     * @param string $sid
     * @param string $captcha
     * @return bool
     * @throws CurlException
     */
    public function checkCaptcha($sid, $captcha)
    {
        $this->preparePostData(self::URL_CHECK_CAPTCHA, ['pCaptcha' => $captcha], $sid);
        return (bool)$this->getResponse();
    }

    /**
     *Get report data from nip
     *
     * @param string $sid
     * @param string $nip
     * @return SearchReport
     */
    public function getByNip($sid, $nip)
    {
        return $this->search($sid, [
            'pParametryWyszukiwania' => [
                'Nip' => $nip
            ]
        ]);
    }

    /**
     * Get report data from regon
     *
     * @param string $sid
     * @param string $regon
     * @return SearchReport
     */
    public function getByRegon($sid, $regon)
    {
        return $this->search($sid, [
            'pParametryWyszukiwania' => [
                'Regon' => $regon
            ]
        ]);
    }

    /**
     * Search by krs
     *
     * @param $sid
     * @param $krs
     * @return SearchReport
     */
    public function getByKrs($sid, $krs)
    {
        return $this->search($sid, [
            'pParametryWyszukiwania' => [
                'Krs' => $krs
            ]
        ]);
    }


    public function getFullData($sid, $regon, $type = ReportType::BASIC_PUBLIC)
    {
        $searchData = [
            'pNazwaRaportu'=>$type,
            'pRegon' => $regon . '00000',
            'pSilosID' => 1
        ];

        $this->preparePostData(self::URL_FULL_REPORT, $searchData, $sid);
        $response = json_decode($this->getResponse());

        switch ($type) {
            case ReportType::BASIC_PUBLIC :
                return $response[0];
                break;
            case ReportType::PUBLIC_ACTIVITY:
                return new ActionReport($response[0]);
                break;
            case ReportType::PUBLIC_LOCALS:
                return new ListsReport($response[0]);
                break;
            default:
                throw new InvalidTypeException(sprintf("Invalid report type: %s", $type));
                break;
        }
    }

    /**
     * @param $sid
     * @param SearchReport $searchReport
     * @return mixed
     * @throws CurlException
     */
    public function getFullReport($sid, SearchReport $searchReport)
    {

        $searchData = [
            'pNazwaRaportu'=>$searchReport->getType(),
            'pRegon' => $searchReport->getRegon14(),
            'pSilosID' => $searchReport->getSilo()
        ];

        $this->preparePostData(self::URL_FULL_REPORT, $searchData, $sid);
        $response = json_decode($this->getResponse());

        return $response[0];
    }

    private function basicSearch($sid, $param, $value)
    {
        return $this->search($sid, $this->getBasicSearchHeader($param, $value));
    }

    /**
     * @param $param
     * @param $value
     * @return array
     */
    private function getBasicSearchHeader($param, $value)
    {
        return [self::BASIC_HEADER_PARAMETER => [
            $param => $value
        ]];
    }

    /**
     * Get url address
     *
     * @param string $address
     * @return string server url
     */
    private function getUrl($address)
    {
        return self::URL_BASIC.$address;
    }

    /**
     * Prepare send data
     *
     * @param array $data
     * @return string json data
     */
    private function prepare(array $data)
    {
        return json_encode($data);
    }

    /**
     * Prepare response to json format
     *
     * @param $response
     * @return string
     */
    private function prepareResponse($response)
    {
        return json_decode($response);
    }

    /**
     * Prepare post data
     *
     * @param string $address
     * @param array $data
     * @param null $sid
     */
    private function preparePostData($address, array $data, $sid = null)
    {
        if (!is_null($sid)) {
            $this->curl->setHeader('sid', $sid);
        }
        $this->curl->post($this->getUrl($address), $this->prepare($data));
    }

    /**
     * Return response server data
     *
     * @return mixed
     * @throws CurlException
     */
    private function getResponse()
    {
        if ($this->curl->error) {
            throw new CurlException($this->curl->error_message);
        }

        return $this->curl->response->d;
    }

    /**
     * @param $sid
     * @param array $searchData
     * @return SearchReport
     * @throws CurlException
     * @throws NotFountException
     */
    private function search($sid, array $searchData)
    {
        $this->preparePostData(self::URL_SEARCH, $searchData, $sid);
        $response = json_decode($this->getResponse());

        if ($response === null) {
            throw new NotFoundException(sprintf("Not found subject"));
        }

        return new SearchReport($response[0]);
    }
}
