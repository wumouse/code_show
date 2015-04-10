<?php

namespace Library\Network;

/**
 * CURL 类，执行之后需要手动保存结果，类不保存结果
 *
 * @author wuhao <wumouse@qq.com>
 * @version $Id$
 *
 * @property string $url 最后一个有效的URL
 * @property string|null $content_type 下载内容的Content-Type:值，NULL表示服务器没有发送有效的Content-Type: header
 * @property int $http_code 最后一个收到的HTTP代码
 * @property int $header_size 接收的header部分的总大小
 * @property int $request_size 发布的header部分的总大小（只支持HTTP）
 * @property int $filetime 远程获取文档的时间，未知时返回-1
 * @property int $ssl_verify_result SSL认证验证的结果（设置了 CURLOPT_SSL_VERIFYPEER）
 * @property int $redirect_count 重定向的次数
 * @property float $total_time 总交换时间的秒数（带微妙）
 * @property float $namelookup_time 名称解析完成的秒数
 * @property float $connect_time 建立连接所花费的秒数
 * @property float $pretransfer_time 从开始请求到文件开始传输的秒数
 * @property int $size_upload 上传的总字节数
 * @property int $size_download 下载的总字节数
 * @property int $speed_download 下载的平均速度
 * @property int $speed_upload 上传的平均速度
 * @property int $download_content_length 从 Content-Length 头里读的下载内容大小
 * @property int $upload_content_length 指定的上传内容大小
 * @property float $starttransfer_time 从请求开始到第一个字节开始上传的时间秒数
 * @property float $redirect_time 最后交换开始前所有重定向所花费的秒数
 * @property string $request_header 请求头，在 curl_setopt(CURLOPT_HEADER_OUT, 1) 时才返回
 */
class Curl
{

    /**
     * 选项，CURL的选项，KEY使用 CURL的常量
     *
     * @var mixed[]
     */
    protected $_opts = [];

    /**
     * 请求信息
     *
     * @var mixed KEY参见 curl_getinfo()
     * @see curl_getinfo
     */
    protected $_info = [];

    /**
     * curl句柄
     *
     * @var resource
     */
    protected $_handler;

    /**
     * 是否真实设置了选项
     *
     * @var bool
     */
    protected $_set = false;

    /**
     * 是否响应 Header 标志
     *
     * @var bool
     */
    protected $_responseHeader = false;

    /**
     * Constructor
     *
     * @internal PHP5.5开始可用
     * @see resetOpts
     * @param array $opts 选项，见 setOpts 方法
     */
    public function __construct(array $opts = null)
    {
        if ($opts) {
            $this->setOpts($opts);
        }
        $this->resetOpts();
    }

    /**
     * 重置选项，PHP5.5开始可用
     *
     * @return $this
     */
    public function resetOpts()
    {
        curl_reset($this->getHandler());
        $this->_opts = [
            CURLOPT_HTTPHEADER => [],
        ];

        return $this;
    }

    /**
     * 获取 curl 句柄 = curl_init() 返回值
     *
     * @return resource
     */
    public function getHandler()
    {
        if (!$this->_handler) {
            $this->_handler = curl_init();
        }

        return $this->_handler;
    }

    /**
     * 获取选项列表
     *
     * @return mixed[]
     */
    public function getOpts()
    {
        return $this->_opts;
    }

    /**
     * 设置选项
     *
     * @param array $opts 见 setOpt 方法
     * @return $this
     */
    public function setOpts(array $opts)
    {
        $this->_opts = $opts + $this->_opts;

        return $this;
    }

    /**
     * 快速执行某个方法
     *
     *
     * @param string $url
     * @param string $method
     * @param mixed $data 数据 CURLOPT_POSTFIELDS 的值
     * @throws \Exception
     * @return string
     */
    public function execMethod($url, $method = 'GET', $data = null)
    {
        $methods = ['GET', 'POST', 'DELETE', 'PUT'];
        if (!in_array($method, $methods)) {
            throw new \InvalidArgumentException('method only can be the follow string:' . implode(',', $methods));
        }
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        $data && $opts[CURLOPT_POSTFIELDS] = $data;
        $this->setOpts($opts);

        return $this->exec();
    }

    /**
     * 执行操作
     *
     * @throws \Exception
     * @return string
     */
    public function exec()
    {
        if (!filter_var($this->_opts[CURLOPT_URL], FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid url: ' . $this->_opts[CURLOPT_URL]);
        }
        $handler = $this->getHandler();
        $this->setOptsReal($this->_opts);
        // 重置信息
        $this->_info = [];
        $result = curl_exec($this->_handler);

        $errNo = curl_errno($handler);
        if (0 === $errNo) {
            // 执行成功后标识为未设置
            $this->_set = false;
            $redirectResult = $this->handleRedirect();
            if ($redirectResult) {
                $result = $redirectResult;
            }
        } else {
            // 执行失败
            throw new \Exception(curl_error($handler), $errNo);
        }
        // 获取信息
        $info = curl_getinfo($handler);
        $this->_info = $info;

        return $result;
    }

    /**
     * 真实调用 curl 设置选项
     *
     * @param array $opts
     * @return $this
     */
    public function setOptsReal(array $opts)
    {
        // 如果已经设置不再设置
        if ($this->_set) {
            return $this;
        }
        // 下载文件时，关闭 return transfer 和 header
        if (isset($opts[CURLOPT_FILE])) {
            $opts[CURLOPT_HEADER] = 0;
        } else {
            // 始终返回结果
            $opts[CURLOPT_RETURNTRANSFER] = 1;
        }

        $handler = $this->getHandler();
        // 可以获取发送的header值
        $opts[CURLINFO_HEADER_OUT] = 1;
        curl_setopt_array($handler, $opts);
        // 设置标识
        $this->_set = true;
        // 标志是否响应了 header，方便分割内容和 header
        !empty($opts[CURLOPT_HEADER]) && $this->_responseHeader = true;

        return $this;
    }

    /**
     * 处理重定向
     *
     * @return string|null
     */
    protected function handleRedirect()
    {
        if (empty($this->_opts[CURLOPT_AUTOREFERER])) {
            return null;
        }
        $code = $this->_info['http_code'];
        switch ($code) {
            // 如果是 302 就执行重定向后的结果
            case 302:
                $redirectUrl = $this->_info['redirect_url'];

                return $this->setOpt(CURLOPT_URL, $redirectUrl)->exec();
                break;
            case 200:
            default:
                break;
        }

        return null;
    }

    /**
     * 设置选项，只是将其保存起来，并未执行 curl_setopt 等函数
     *
     * @param int $key 选项键，使用CURLOPT_* 常量
     * @param mixed $value
     * @return $this
     */
    public function setOpt($key, $value)
    {
        $this->_opts[$key] = $value;

        return $this;
    }

    /**
     * 获取 CURL执行信息 curl_getinfo
     *
     * @deprecated 命名有问题，请使用 getInfo
     * @return mixed
     */
    public function getInfos()
    {
        return $this->getInfo();
    }

    /**
     * 获取 CURL执行信息 curl_getinfo
     *
     * @see curl_getinfo
     * @return mixed
     */
    public function getInfo()
    {
        return $this->_info;
    }

    /**
     * 设置头信息
     *
     * @param string $name
     * @param mixed $value
     * @return $this
     */
    public function setHeader($name, $value)
    {
        $raw = $name . ': ' . $value;
        $this->_opts[CURLOPT_HTTPHEADER][] = $raw;

        return $this;
    }

    /**
     * 设置多个头信息
     *
     * @param array $headers 键值包含完整的条目，键名无意义
     * @return $this
     */
    public function setRawHeaders(array $headers)
    {
        $this->_opts[CURLOPT_HTTPHEADER] = array_merge($headers, $this->_opts[CURLOPT_HTTPHEADER]);

        return $this;
    }

    /**
     * 分离响应数据
     *
     * @param string $response
     * @return string[]
     */
    public function splitResponse($response)
    {
        $return = [
            'headers' => '',
            'body' => '',
        ];
        if (is_string($response)) {
            // 通过中间的隔行分开
            $parts = explode("\r\n\r\n", $response, 2);
            // 如果有响应头
            if ($this->_responseHeader) {
                $return['headers'] = $parts[0];
                $return['body'] = $parts[1];
            } else {
                $return['body'] = $parts[0];
            }
        }

        return $return;
    }

    /**
     * 解析头信息，返回 名为KEY的数组
     *
     * @param string $headersStr
     * @return mixed[]
     */
    public function parseHeaders($headersStr)
    {
        $headerLines = explode("\r\n", $headersStr);
        $headers = [];
        foreach ($headerLines as $key => $line) {
            if (strpos($line, ':')) {
                list($name, $value) = explode(': ', $line, 2);
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * 获取 curl info 属性
     *
     * @param string $key 见 curl_getinfo
     * @throws \InvalidArgumentException
     * @return mixed
     */
    public function __get($key)
    {
        if (isset($this->_info[$key])) {
            return $this->_info[$key];
        } else {
            throw new \InvalidArgumentException("Invalid key: {$key} , see curl_getinfo");
        }
    }

    /**
     * 销毁 curl 句柄
     *
     * @return void
     */
    public function __destruct()
    {
        is_resource($this->_handler) && curl_close($this->_handler);
    }

}
