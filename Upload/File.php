<?php

namespace Library\Upload;

use Library\InjectionAware;
use Phalcon\Config;
use Phalcon\Http\Request\File as RequestFile;

/**
 * 上传文件处理
 *
 * 配置文件 config/upload.php
 * return [
 * 'saveDir' => '../upload',
 *
 * // 使用 Image 对象时可用
 * //'image' => [
 * //'minWidth' => 1024,
 * //],
 * ];
 *
 * // 设置DI
 * $di->set('uploadConfig', function () {
 * return new Phalcon\Config\Adapter\Php('../config/config.php');
 * }, true);
 *
 * $di->set('fileUploader', function () use ($di) {
 * return new Library\Upload\File($di->get('uploadConfig')->local);
 * }, true);
 *
 * // 控制器或业务逻辑层
 * if (!$this->request->hasFiles()) {
 * throw new \InvalidArgumentException('There has no files uploaded');
 * }
 *
 * $uploader = $this->di->get('fileUploader');
 * $uploader->setFile($this->request->getUploadedFiles());
 * // 使用自动生成
 * $uploader->save();
 *
 * $uploader->setOptions([
 * 'subDir' => date('Ymd'),// 可设置子目录
 * ]);
 * // 手动设置基本名
 * $uploader->save('aBaseNameWithoutExtension');
 *
 * // 获取文件信息
 * print_r($uploader->getFileInfo());
 *
 * @author wuhao <wumouse@qq.com>
 * @version $Id$
 */
class File extends InjectionAware
{

    /**
     * Phalcon 文件对象
     *
     * @var RequestFile
     */
    protected $_file;

    /**
     * 文件信息
     *
     * @var mixed[]
     */
    protected $_fileInfo = [];

    /**
     * 选项
     *
     * @var Config|array
     */
    protected $_options = [
        'maxSize' => null,// 以 kb为单位
        'allowedExts' => [],// 以点开头
        'allowedMimeTypes' => [],// image/png 这样的
        'saveDir' => '',// 保存路径，基本路径
        'subDir' => '',// 子目录，用于在程序中设置
        'nameGeneration' => 'uniqid',// 文件基本名生成函数，使用 save 时没传入文件名时使用，可以是任意的 callable 类型
        'fileMode' => null,// 上传完文件后设置的权限数字
    ];

    /**
     * 继承父类的 setOptions 方法都要将配置更改为 Config 对象
     *
     *
     * @param array|Config $options
     * @throws Exception
     * @throws Exception
     */
    public function __construct($options = null)
    {
        $this->_options = new Config($this->_options);
        $options && $this->setOptions($options);
        if (!$this->_options->saveDir) {
            throw new Exception('The saveDir must set');
        }
    }

    /**
     * 获取当前 File对象
     *
     * @return RequestFile
     */
    public function getFile()
    {
        return $this->_file;
    }

    /**
     * 设置文件
     *
     * @throws Exception 上传文件出现错误
     * @throws \InvalidArgumentException 文件不是从请求传来的
     * @param RequestFile $file
     * @return $this
     */
    public function setFile(RequestFile $file)
    {
        if (!$file->isUploadedFile()) {
            throw new \InvalidArgumentException('File is not upload from request');
        }
        $errorCode = $file->getError();
        if ($errorCode != UPLOAD_ERR_OK) {
            throw new Exception("The uploaded file has a error with code:{$errorCode}");
        }
        $this->_file = $file;

        // 这里 1.3.3 版本 RequestFile::getExtension 有BUG，明明有值，未返回，只有通过名字获取了
        // 重置了 _fileInfo
        $this->_fileInfo = ['extension' => $this->getExtension(),];

        return $this;
    }

    /**
     * 获取扩展名，带点的
     *
     * @return string
     */
    public function getExtension()
    {
        return strrchr($this->_file->getName(), '.');
    }

    /**
     * 保存文件，会执行检查创建目录，生成文件名，移动文件到指定位置
     *
     * @throws Exception 必须先调用 setFile
     * @param string $fileBaseName 文件基本名，无扩展名
     * @return bool
     */
    public function save($fileBaseName = null)
    {
        $this->_options['saveDir'] = $this->_mkdir($this->_options['saveDir']);
        if (!$this->_file instanceof RequestFile) {
            throw new Exception('Please call setFile first');
        }
        $this->check();
        $destination = $this->getDestination($fileBaseName);
        $this->_fileInfo['destination'] = $destination;

        return $this->moveTo($destination);
    }

    /**
     * 创建目录方法
     *
     * @throws Exception 创建目录失败
     * @param string $dir
     * @return string 新的完整路径
     */
    protected function _mkdir($dir)
    {
        if (!stream_resolve_include_path($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception("The dir {$dir} can not be created!");
            }
        }

        return stream_resolve_include_path($dir);
    }

    /**
     * 相关检查
     *
     * @throws \InvalidArgumentException 检查未通过
     */
    public function check()
    {
        $this->checkExtension();
        $this->checkRealType();
        $this->checkSize();
    }

    /**
     * 检查扩展名是否允许
     *
     * @throws \InvalidArgumentException
     * @return $this
     */
    public function checkExtension()
    {
        /** @var array $allowedExts */
        $allowedExts = $this->_options->allowedExts;
        if (count($allowedExts)) {
            $extension = $this->getExtension();
            foreach ($allowedExts as $ext) {
                if ($ext === $extension) {
                    return $this;
                }
            }
            $this->_fileInfo['extension'] = '';
            throw new \InvalidArgumentException("{$extension} is not allowed");
        }

        return $this;
    }

    /**
     * 检查 Mime 类型，普通文件从请求上来的只是读取请求给出的 Mime，请求是可以随意指定的
     * 图片可以读取文件头，其他文件类型太杂乱不作判断， fileInfo 函数和扩展都被废弃或取代，不用
     *
     * @throws \InvalidArgumentException
     * @return $this
     */
    public function checkRealType()
    {
        /** @var array $allowedMineTypes */
        $allowedMineTypes = $this->_options->allowedMimeTypes;
        if (count($allowedMineTypes)) {
            $realMineType = $this->getRealType();
            foreach ($allowedMineTypes as $mineType) {
                if ($mineType === $realMineType) {
                    $this->_fileInfo['mime'] = $realMineType;

                    return $this;
                }
            }
            throw new \InvalidArgumentException("The file type {$realMineType} is not allowed!");
        }

        return $this;
    }

    /**
     * 获取真实 mime 类型
     *
     * @return string
     */
    public function getRealType()
    {
        return $this->_file->getRealType();
    }

    /**
     * 检查文件大小
     *
     * @throws \InvalidArgumentException
     * @return $this
     */
    public function checkSize()
    {
        $maxSize = $this->_options->maxSize;
        $size = $this->_file->getSize();

        if ($maxSize) {
            if ($size > ($maxSize * 1024)) {
                throw new \InvalidArgumentException("Size of file must be less than {$maxSize} kb");
            }
        }
        $this->_fileInfo['size'] = $size;

        return $this;
    }

    /**
     * 生成文件目标路径
     *
     * @param string $fileBaseName 没有扩展名的文件名
     * @return string 目标路径
     */
    public function getDestination($fileBaseName = null)
    {
        if (!$fileBaseName) {
            $fileBaseName = $this->generateName();
        }

        $fileName = $this->_getFileName($fileBaseName);
        $dir = $this->_options['saveDir'];
        if ($this->_options['subDir']) {
            $dir .= DIRECTORY_SEPARATOR . $this->_options['subDir'];
            $dir = $this->_mkdir($dir);
        }

        return $dir . DIRECTORY_SEPARATOR . $fileName;
    }

    /**
     * 生成基本名，nameGeneration 配置要可调用
     *
     * @throws Exception 生成文件基本名的 callable 不可用
     * @return string
     */
    public function generateName()
    {
        if (is_callable($this->_options['nameGeneration'])) {
            $name = call_user_func($this->_options['nameGeneration']);
        } else {
            throw new Exception('Name generation is not callable!');
        }

        return $name;
    }

    /**
     * 获取文件全名
     *
     * @param string $fileBaseName 文件基本名
     * @return string
     */
    protected function _getFileName($fileBaseName)
    {
        return $this->_fileInfo['fileName'] = $fileBaseName . $this->_fileInfo['extension'];
    }

    /**
     * 移动文件方法
     *
     * @throws Exception 上传目录不存在
     * @param string $destination 目标路径
     * @return bool
     */
    public function moveTo($destination)
    {
        $this->_checkFileDirExists($destination);
        $result = $this->_moveTo($destination);
        $result && $this->_changeFileMode($destination);

        return $result;
    }

    /**
     * 检查文件的目录是否存在，这里不创建目录
     *
     * @throws Exception 目录不存在
     * @param string $destination
     */
    protected function _checkFileDirExists($destination)
    {
        // 检查写入文件目录是否存在
        if (!stream_resolve_include_path($dirname = dirname($destination))) {
            throw new Exception("The dir {$dirname} does not exists!");
        }
    }

    /**
     * 移动文件
     *
     * @param mixed $destination
     * @return bool
     */
    protected function _moveTo($destination)
    {
        return $this->_file->moveTo($destination);
    }

    /**
     * 改变文件的 mode
     *
     * @param string $destination
     * @return void
     */
    protected function _changeFileMode($destination)
    {
        if ($this->_options['fileMode']) {
            // 更改权限
            chmod($destination, $this->_options['fileMode']);
        }
    }

    /**
     * 获取文件分析后的信息
     *
     * @deprecated 命名修正，请使用 getFileInfo
     * @return \mixed[]
     */
    public function getFileInfos()
    {
        return $this->getFileInfo();
    }

    /**
     * 获取文件分析后的信息
     *
     * @return \mixed[]
     */
    public function getFileInfo()
    {
        return $this->_fileInfo;
    }

}
