<?php
namespace tranlongpc\LaravelThumbnail;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\File;
class LaravelThumbnail
{
    //type:
    // fit - best fit possible for given width & height - by default
    //resize - exact resize of image
    //background - fit image perfectly keeping ratio and adding black background
    //resizeCanvas - keep only center
    public static function thumb($path, $width = null, $height = null, $type = "fit")
    {
        $images_path = config('thumb.images_path');
        $media_path = config('thumb.media_path');
        $path = ltrim($path, "/");

        //if path exists and is image
        if(File::exists(public_path("{$images_path}/" . $path))){

            $allowedMimeTypes = ['image/jpeg', 'image/gif', 'image/png'];
            $contentType = mime_content_type(public_path("{$images_path}/" . $path));

            if(in_array($contentType, $allowedMimeTypes)){
                //returns the original image if no width and height
                if (is_null($width) && is_null($height)) {
                    return url("{$images_path}/" . $path);
                }


                $file_name = $path;
                $path_build = $images_path . '/' . $media_path . '/' . $type . '_' . $width . 'x' . $height . '/';

                $config_file_name = config('thumb.file_name');
                if($config_file_name['rewrite']) {

                    $prefix = '';
                    if($config_file_name['prefix']){
                        $prefix = $config_file_name['prefix'] .'_';
                    }
                    $path_replace = str_replace($config_file_name['remove'], "", $path);
                    $path_replace = str_replace('/', $config_file_name['space'], $path_replace);
                    $file_name =  $prefix . $path_replace ;
                }

                //if thumbnail exist returns it
                if (File::exists(public_path($path_build . $file_name))) {
                    return url($path_build . $file_name);
                }


                $image = Image::make(public_path("{$images_path}/" . $path));

                switch ($type) {
                    case "fit": {
                        $image->fit($width, $height, function ($constraint) {
                        });
                        break;
                    }
                    case "crop": {
                        //stretched
                        $image->fit($width, $height);
                        $image->crop($width, $height);
                    }
                    case "resize": {
                        //stretched
                        $image->resize($width, $height);
                    }
                    case "background": {
                        $image->resize($width, $height, function ($constraint) {
                            //keeps aspect ratio and sets black background
                            $constraint->aspectRatio();
                            $constraint->upsize();
                        });
                    }
                    case "resizeCanvas": {
                        $image->resizeCanvas($width, $height, 'center', false, 'rgba(0, 0, 0, 0)'); //gets the center part
                    }
                }

                //relative directory path starting from main directory of images
                $dir_path = (dirname($path) == '.') ? "" : dirname($path);

                //Create the directory if it doesn't exist
                if (!File::exists(public_path($path_build ))) {
                    File::makeDirectory(public_path($path_build ), 0775, true);
                }

                //Save the thumbnail
                $image->save(public_path($path_build . $file_name));

                //return the url of the thumbnail
                return $path_build . $file_name;

            } else {
                $width = is_null($width) ? 400 : $width;
                $height = is_null($height) ? 400 : $height;

                // returns an image placeholder generated from placehold.it
                return "http://placehold.it/{$width}x{$height}/9e9e9e/ffffff?text=TimXe";
            }

        } else {
            $width = is_null($width) ? 400 : $width;
            $height = is_null($height) ? 400 : $height;

            // returns an image placeholder generated from placehold.it
            return "http://placehold.it/{$width}x{$height}/9e9e9e/ffffff?text=TimXe";
        }
    }

    public static function thumb_s3($path, $width = 250, $height = 180, $type = "fit")
    {
        $config = config('filesystems.disks.media_thumb');
        $s3_folder = config('thumb.s3_folder');
        $domain_cloud =  config('filesystems.disks.cloud.url');
        $path_remove_domain = str_replace($domain_cloud, '', $path);

        //check has_thumbnail
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $type_path = $width .'_'. $height .'_'. $type;
        $thumbnail = encode_md5($path_remove_domain). '.'. $extension;
        $thumbnail_path = $s3_folder . $config['path'] . $type_path .'/'. $thumbnail;
        $thumbnail_url = $config['url'] . $thumbnail_path;


        $key_cache = 'check_file_thumb_'. encode_md5($thumbnail_url);
        Renew_cache($key_cache);
        if (Cache::has($key_cache)) {
            return Cache::get($key_cache);
        } else {
            $check_thumbnail_has = Storage::disk('cloud')->exists($thumbnail_path);
            if($check_thumbnail_has){
                Cache::put($key_cache, $thumbnail_url, 10000);
                return $thumbnail_url;
            } else {
                $this_file = false;
                if(strpos($path, $domain_cloud) !== false) {
                    $this_file = Storage::disk('cloud')->exists($path_remove_domain);
                } else {
                    if(strpos($path, parse_url(config('app.url'), PHP_URL_HOST)) !== false){
                        $this_file = true;
                    } else {
                        if (File::exists(public_path( $path))) {
                            $path = url($path);
                            $this_file = true;
                        }
                    }
                }
                if($this_file){
                    //create thumbnail
                    $image = Image::make($path);
                    switch ($type) {
                        case "fit": {
                            $image->fit($width, $height, function ($constraint) {
                            });
                            break;
                        }
                        case "crop": {
                            //stretched
                            $image->fit($width, $height);
                            $image->crop($width, $height);
                        }
                        case "resize": {
                            //stretched
                            $image->resize($width, $height);
                        }
                        case "background": {
                            $image->resize($width, $height, function ($constraint) {
                                //keeps aspect ratio and sets black background
                                $constraint->aspectRatio();
                                $constraint->upsize();
                            });
                        }
                        case "resizeCanvas": {
                            $image->resizeCanvas($width, $height, 'center', false, 'rgba(0, 0, 0, 0)'); //gets the center part
                        }
                    }

                    $image->stream();
                    //Save the thumbnail
                    Storage::disk('media_thumb')->put($thumbnail_path, $image, 'public');
                    Cache::put($key_cache, $thumbnail_url, 10000);
                    return $thumbnail_url;
                } else {
                    $width = is_null($width) ? 400 : $width;
                    $height = is_null($height) ? 400 : $height;

                    // returns an image placeholder generated from placehold.it
                    $thumbnail_url = "http://placehold.it/{$width}x{$height}/9e9e9e/ffffff?text=TimXe";
                    Cache::put($key_cache, $thumbnail_url, 10000);
                    return $thumbnail_url;
                }
            }
        }



    }


}