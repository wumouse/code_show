<?php

namespace Library\Upload;

use Phalcon\Config;
use Phalcon\Http\Request\File as RequestFile;
use Phalcon\Image\Adapter as PhaImageAdapter;
use Phalcon\Image\Exception as ImageException;

/**
 * 上传图片，需要 DI里注册一个 image 服务，这个image服务是 继承自 Phalcon\Image\Adapter的
 *
 *
 * 基本使用请参看 File
 *
 * $di->set('image', function ($filePath) {
 * return new Phalcon\Image\Adapter\GD($filePath);
 * });
 *
 * $di->set('imageUploader', function () use ($di) {
 * return new Library\Upload\Image($di->get('uploadConfig')->local);
 * }, true);
 *
 * $uploader = $this->di->get('imageUploader');
 * $uploader->setFile($this->request->getUploadedFiles());
 *
 * // 这里必须以 image 为键设置，另外有一个替代的方法 setImageOptions
 * $uploader->setOptions([
 * 'image' => [
 * 'saveAsJpeg' => true,// 保存为 JPEG
 * ],
 * ]);
 *
 * // 另外一种方式
 * $uploader->setImageOptions([
 * 'saveAsJpeg' => true,// 保存为 JPEG
 * ]);
 *
 * // 对图像进行处理
 * $imageHandler = $uploader->getImageHandler();
 * $imageHandler->resize(200, 400);
 *
 * // 保存图像
 * $uploader->save('resizedPicture');
 *
 * // 获取文件信息，包括图片的高宽等
 * print_r($uploader->getFileInfo());
 *
 * @see File
 * @author wuhao <wumouse@qq.com>
 * @version $Id$
 */
class Image extends File
{

    /**
     * 图像初始化选项
     *
     * @var Config
     */
    protected static $_imageInitOptions = [
        'image' => [
            // 最小宽度
            'minWidth' => null,
            // 最小高度
            'minHeight' => null,
            // 最大宽度
            'maxWidth' => null,
            // 最大高度
            'maxHeight' => null,
            // 保存为JPEG
            'saveAsJpeg' => null,
            // JPG图片质量
            'jpegQuality' => 75,
            // 缩略图
            // 'thumb' => false,
            // 缩略图最大宽度
            // 'thumbMaxWidth' => null,
            // 缩略图最大高度
            // 'thumbMaxHeight' => null,
            // 隔行扫描
            // 'interlace' => true,
        ],
    ];
    /**
     * 图像处理器
     *
     * @var PhaImageAdapter
     */
    protected $_imageHandler;

    /**
     * 合并选项
     *
     * @param array|Config $options
     * @throws Exception
     */
    public function __construct($options = null)
    {
        $this->_options += self::$_imageInitOptions;
        parent::__construct($options);
    }

    /**
     * 设置图像选项
     *
     * @param array|Config $options
     * @return void
     */
    public function setImageOptions($options)
    {
        parent::setOptions(['image' => $options]);
    }

    /**
     * 额外处理图片
     *
     * @throws \InvalidArgumentException 无效的图像文件
     * @param RequestFile $file
     * @return void
     */
    public function setFile(RequestFile $file)
    {
        parent::setFile($file);
        try {
            $filePath = $file->getTempName();
            $imageHandler = $this->getDI()->get('image', [$filePath]);
        } catch (ImageException $e) {
            throw new \InvalidArgumentException("{$filePath} is not a valid image file");
        }
        $this->_fileInfo['width'] = $imageHandler->getWidth();
        $this->_fileInfo['height'] = $imageHandler->getHeight();
        $this->_fileInfo['type'] = $imageHandler->getType();
        $this->_fileInfo['mime'] = $imageHandler->getMime();

        $this->_imageHandler = $imageHandler;
    }

    /**
     * 额外检查像素大小
     */
    public function check()
    {
        $this->checkPixels();
        parent::check();
    }

    /**
     * 检查像素
     *
     * @return $this
     */
    public function checkPixels()
    {
        $imageInfo = $this->_fileInfo;
        /** @var Config $imageOptions */
        $imageOptions = $this->_options->image;
        $imageName = $this->_file->getName();

        if ($imageOptions->minWidth && $imageInfo['width'] < $imageOptions->minWidth) {
            throw new \InvalidArgumentException("Width of {$imageName} must greater than {$imageOptions->minWidth} px");
        }
        if ($imageOptions->minHeight && $imageInfo['height'] < $imageOptions->minHeight) {
            throw new \InvalidArgumentException("Height of {$imageName} must greater than {$imageOptions->minHeight} px");
        }
        if ($imageOptions->maxWidth && $imageInfo['width'] > $imageOptions->maxWidth) {
            throw new \InvalidArgumentException("Width of {$imageName} must less than {$imageOptions->maxWidth} px");
        }
        if ($imageOptions->maxHeight && $imageInfo['height'] > $imageOptions->maxHeight) {
            throw new \InvalidArgumentException("Height of {$imageName} must less than {$imageOptions->maxHeight} px");
        }

        return $this;
    }

    /**
     * 覆盖父类 _moveTo，使用 imageHandler 来保存文件，方便对图片进行处理
     *
     * @param string $destination 目的路径
     * @return bool
     */
    protected function _moveTo($destination)
    {
        return $this->_imageHandler->save($destination, $this->_options->image->jpegQuality);
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getRealType()
    {
        return $this->_fileInfo['mime'];
    }

    /**
     * {@inheritDoc}
     *
     * @param string $fileBaseName 文件基本名
     * @return string
     */
    protected function _getFileName($fileBaseName)
    {
        if ($this->_options->image->saveAsJpeg) {
            $extension = '.jpg';
        } else {
            $extension = $this->_fileInfo['extension'];
        }

        return $this->_fileInfo['fileName'] = $fileBaseName . $extension;
    }

    /**
     * 获取处理图像的handler
     *
     * @return PhaImageAdapter
     */
    public function getImageHandler()
    {
        return $this->_imageHandler;
    }

}
