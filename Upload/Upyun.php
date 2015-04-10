<?php

namespace Library\Upload;

use Library\InjectionAware;
use Library\Network\Curl;
use Phalcon\Config;
use Phalcon\DiInterface;

/**
 * UpYun 管理，必须首先调用 setDI，如果设置进 DI的时候会自动被调用，所以无需手动
 *
 * 相关配置文档见：http://wiki.upyun.com/index.php?title=HTTP_REST_API%E6%8E%A5%E5%8F%A3
 *
 * @author wuhao <wumouse@qq.com>
 * @version $Id$
 */
class Upyun extends InjectionAware/*  implements FileServiceInterface */
{

    /** 上传线路 - 自动 */
    const ENDPOINT_AUTO = 'v0.api.upyun.com';
    /** 上传线路 - 电信 */
    const ENDPOINT_TELECOM = 'v1.api.upyun.com';
    /** 上传线路 - 联通(网通) */
    const ENDPOINT_CNC = 'v2.api.upyun.com';
    /** 上传线路 - 移动(铁通) */
    const ENDPOINT_CTT = 'v3.api.upyun.com';

    /** 图片预处理参数 - 在 UPYUN 管理平台创建好缩略图版本该缩略方式包含了所需的缩略参数，参数更简洁，使用更方便 */
    const X_GMKERL_THUMBNAIL = 'x-gmkerl-thumbnail';

    /** 缩略类型 */
    const X_GMKERL_TYPE = 'x-gmkerl-type';

    /** 缩略类型对应的参数值，单位为像素，须搭配 x-gmkerl-type 使用 */
    const X_GMKERL_VALUE = 'x-gmkerl-value';

    /** 默认 95 图片压缩质量，可选（1~100）*/
    const X_GMKERL_QUALITY = 'x­gmkerl-quality';

    /** 默认 true 图片锐化 */
    const X_GMKERL_UNSHARP = 'x­gmkerl-unsharp';

    /** 默认 false 即删除 是否保留原图的 EXIF 信息 */
    const X_GMKERL_EXIF_SWITCH = 'x-gmkerl-exif-switch';

    /** 旋转角度，目前只允许设置：auto, 90, 180, 270 */
    const X_GMKERL_ROTATE = 'x-gmkerl-rotate';

    /**
     * 选项
     *
     * @var mixed[]
     */
    protected $_options = [
        'resetOpts' => true,// 是否自动重置CURL选项，

        'username' => '',// 用户名
        'password' => '',// 密码
        'bucketname' => '',// 操作的目录
        'endpoint' => self::ENDPOINT_AUTO,// 线路
        'timeout' => 5,// 超时时间

        // 图像处理选项，如果需要使用这些选项，请使用 uploadImage 方法，否则使用 upload 方法即可
        'image' => [
            self::X_GMKERL_TYPE => null,
            self::X_GMKERL_VALUE => null,
            self::X_GMKERL_QUALITY => null,
            self::X_GMKERL_UNSHARP => null,
            self::X_GMKERL_THUMBNAIL => null,
            self::X_GMKERL_EXIF_SWITCH => true,// 默认不删除 exif信息
            self::X_GMKERL_ROTATE => null,
        ],
    ];

    /**
     * CURL
     *
     * @var Curl
     */
    protected $_curl;

    /**
     * 响应内容
     *
     * @var string[]
     */
    protected $_response = [];

    /**
     * 存在选项就设置选项
     *
     * @param array|Config $options
     */
    public function __construct($options = null)
    {
        $this->_options = new Config($this->_options);
        $options && $this->setOptions($options);
    }

    /**
     * 覆盖父类的设置选项，将密码加密
     *
     * @param mixed $options
     * @throws Exception
     */
    public function setOptions($options)
    {
        parent::setOptions($options);
        if (isset($options['password'])) {
            $this->_options['password'] = md5($options['password']);
        }
    }

    /**
     * 设置DI，注入时会自动调用该函数，或者手动调用该函数
     *
     * @param DiInterface $di
     * @return void
     */
    public function setDI($di)
    {
        $this->_curl = $di->get('curl');
        parent::setDI($di);
    }

    /**
     * 获取CURL
     *
     * @return Curl
     */
    public function getCurl()
    {
        return $this->_curl;
    }

    /**
     * 上传图片，如果不作处理，可以使用 upload 即可，当然，必须先设置选项，可选选项只在本次有效
     *
     * @param string $uri
     * @param string|resource $fileResourceOrPathOrContent
     * @param array|null $extraImageOptions 额外的图像选项，见 $this->_options
     * @return bool 上传失败成功
     */
    public function uploadImage($uri, $fileResourceOrPathOrContent, array $extraImageOptions = null)
    {
        $headers = [];
        $options = $this->_options['image'];
        // 合并额外参数，但是不放入到主要设置当中
        $extraImageOptions && $options->merge(new Config($extraImageOptions));
        // 设置 图像参数头
        foreach ($options as $name => $value) {
            if ($value && isset($this->_options['image'])) {
                $headers[] = $name . ': ' . $value;
            }
        }
        $this->_curl->setRawHeaders($headers);

        return $this->upload($uri, $fileResourceOrPathOrContent);
    }

    /**
     * 上传文件
     *
     * @param string $uri 要创建的文件URI，必须带前置斜杠
     * @param string|resource $fileResourceOrPathOrContent 本地文件路径或者已经打开的文件资源类型或者文件内容
     * @return bool 上传失败或成功
     * @throws Exception
     */
    public function upload($uri, $fileResourceOrPathOrContent)
    {
        $method = 'PUT';
        $contentLength = 0;
        if (is_string($fileResourceOrPathOrContent)) {
            // 是路径
            if ($fileFullPath = stream_resolve_include_path($fileResourceOrPathOrContent)) {
                $fileResourceOrPathOrContent = fopen($fileFullPath, 'r');
                $contentLength = $this->handleFileResource($fileResourceOrPathOrContent);
                // 是文件内容
            } else {
                $contentLength = strlen($fileResourceOrPathOrContent);
                $this->_curl->setOpt(CURLOPT_POSTFIELDS, $fileResourceOrPathOrContent);
            }
            // 是资源
        } elseif (is_resource($fileResourceOrPathOrContent)) {
            $contentLength = $this->handleFileResource($fileResourceOrPathOrContent);
        }

        $this->_curl->setOpt(CURLOPT_POST, true);

        return $this->_request($method, $uri, $contentLength);
    }

    /**
     * 处理文件资源类型
     *
     * @param resource $resource 文件资源 fopen的返回值等
     * @throws Exception
     * @return int 内容的长度
     */
    protected function handleFileResource($resource)
    {
        if (is_resource($resource)) {
            // 计算大小
            fseek($resource, 0, SEEK_END);
            $contentLength = ftell($resource);
            fseek($resource, 0);
            // 使用 INFILE 方式上传
            $this->_curl->setOpts([
                CURLOPT_INFILE => $resource,
                CURLOPT_INFILESIZE => $contentLength,
            ]);

            return $contentLength;
        } else {
            $type = gettype($resource);
            throw new Exception("Argument 1 passed to handleFileResource() must be of the type array, {$type} given");
        }
    }

    /**
     * 发送请求，请求完毕，将内容和头存入 _response
     *
     *
     * @param string $method 请求方法,须大写
     * @param string $uri 请求的URI，必须带前置斜杠
     * @param int $contentLength 内容长度
     * @throws Exception
     * @throws \Exception 请求响应不是 200的时候
     * @return bool 请求响应不是 200的时候
     */
    protected function _request($method, $uri, $contentLength = 0)
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        // 拼接 bucket 和 uri
        $uri = "{$this->_options['bucketname']}{$uri}";
        // 拼接使用的线路
        $url = 'http://' . $this->_options['endpoint'] . $uri;
        $this->_curl->setOpts([
            CURLOPT_CUSTOMREQUEST => $method,// 请求方法
            CURLOPT_URL => $url,// 地址
            CURLOPT_HEADER => 1,// 输出Header
            CURLOPT_TIMEOUT => $this->_options['timeout'],// 设置超时
        ]);

        // 设置头信息
        $this->_curl->setRawHeaders([
            'Expect:',
            "Date: {$date}", // 认证
            sprintf('Authorization: Upyun %s:%s', $this->_options['username'],
                $this->sign($method, $uri, $date, $contentLength)),
            "Content-Length: {$contentLength}",
        ]);

        $response = $this->_curl->exec();

        // 重置设置
        $this->_options['resetOpts'] && $this->_curl->resetOpts();
        // 分开响应的头和BODY
        $this->_response = $this->_curl->splitResponse($response);
        // 失败处理
        if ($this->_curl->http_code != 200) {
            $this->handleError($this->_curl->http_code);

            return false;
        } else {
            return true;
        }
    }

    /**
     * 签名生成
     *
     * @param string $method 请求方法
     * @param string $uri 发送的URI，必须带前置斜杠
     * @param string $date gmdate('D, d M Y H:i:s \G\M\T');
     * @param int $contentLength 文件大小
     * @return string 签名后的字符串
     */
    protected function sign($method, $uri, $date, $contentLength)
    {
        // 密码必须是 md5 过后的密码
        $sign = md5("{$method}&{$uri}&{$date}&{$contentLength}&{$this->_options['password']}");

        return $sign;
    }

    /**
     * 处理响应码不是 200 的错误
     *
     * @throws Exception
     * @param int $httpCode 响应码
     * @return void
     */
    protected function handleError($httpCode)
    {
        if ($this->_response['headers']) {
            $pos = strpos($this->_response['headers'], "\r\n");
            // 只获取第一行
            $firstHeader = substr($this->_response['headers'], 0, $pos);
            // 获取又拍云的自定义消息
            list(, , $msg) = explode(' ', $firstHeader, 3);
        } else {
            $msg = 'Invalid Response';
        }
        throw new Exception($msg, $httpCode);
    }

    /**
     * 下载文件保存
     *
     * @param string $uri 文件URI，必须带前置斜杠
     * @param resource $fileHandler 文件资源
     * @throws Exception
     * @return bool 成功失败
     */
    public function download($uri, $fileHandler)
    {
        if (is_resource($fileHandler)) {
            $method = 'GET';
            // 设置文件Handler，Curl类会自动关闭 return transfer 和 header 输出
            $this->_curl->setOpts([
                CURLOPT_FILE => $fileHandler,
            ]);

            return $this->_request($method, $uri);
        } else {
            $type = gettype($fileHandler);
            throw new Exception("Argument 1 passed to download() must be of the type array, {$type} given");
        }
    }

    /**
     * 获取文件信息
     *
     * @param string $uri 文件URI，必须带前置斜杠
     * @return mixed[]
     */
    public function getFileInfo($uri)
    {
        $method = 'HEAD';
        // 不需要 body，只读取头信息
        $this->_curl->setOpts([
            CURLOPT_NOBODY => 1,
            CURLOPT_HEADER => 1,
        ]);
        $this->_request($method, $uri);

        // 解析数据为数组
        return $this->parseYpyunInfo($this->_response['headers']);
    }

    /**
     * 解析又拍云头信息，返回文件类型，大小，时间等
     *
     * @param string $headers 头信息字符串
     * @return mixed[]
     */
    protected function parseYpyunInfo($headers)
    {
        $count = preg_match_all('#x-upyun-file-(\w+): (.+)#', $headers, $matches);
        $info = [];
        if ($count > 1) {
            // 改写匹配信息
            for ($i = 0, $count = count($matches[1]); $i < $count; $i++) {
                $info[$matches[1][$i]] = $matches[2][$i];
            }
        }

        return $info;
    }

    /**
     * 删除文件
     *
     * @param string $uri 文件URI，必须带前置斜杠
     * @return bool 成功失败
     */
    public function delete($uri)
    {
        $method = 'DELETE';

        return $this->_request($method, $uri);
    }

    /**
     * 创建目录
     *
     * @param string $uri 目录URI，必须带前置斜杠
     * @param bool $recursive 是否递归创建不存在的目录
     * @return bool 成功失败
     */
    public function mkdir($uri, $recursive = false)
    {
        $method = 'POST';
        // 设置创建目录的头
        $this->_curl->setHeader('Folder', 'true');
        $recursive && $this->_curl->setHeader('Mkdir', 'true');

        return $this->_request($method, $uri);
    }

    /**
     * 删除目录
     *
     * @param string $uri 目录URI，必须带前置斜杠，最好加上尾斜杠
     * @return bool 成功失败
     */
    public function rmdir($uri)
    {
        $method = 'DELETE';

        return $this->_request($method, $uri);
    }

    /**
     * 获取目录文件列表
     *
     * @param string $uri URI，为空时就是 bucket 目录，必须带前置斜杠
     * @return mixed[]
     */
    public function getListOfDir($uri = null)
    {
        $method = 'GET';
        $this->_request($method, $uri);
        // 分析 BODY
        $lines = explode("\n", $this->_response['body']);
        $list = [];
        foreach ($lines as $key => $line) {

            $items = explode("\t", $line);
            $list[$key] = [
                'name' => $items[0],
                'type' => $items[1] == 'F' ? 'folder' : 'file',
                'size' => $items[2],
                'time' => $items[3],
            ];
        }

        return $list;
    }

    /**
     * 获取空间使用情况
     *
     * @param string $uri 为空时为 bucket 根目录
     * @return string
     */
    public function getUsage($uri = null)
    {
        $method = 'GET';
        $this->_request($method, $uri .= '/?usage');

        return $this->_response['body'];
    }

    /**
     * 获取响应
     *
     * @return string[] 包含 headers 和 body 两个键名的数组
     */
    public function getResponse()
    {
        return $this->_response;
    }

}
