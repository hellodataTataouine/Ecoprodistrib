<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Validation\Rule;
use App\BasicExtended as BE;
use App\BasicExtra;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Language;
use App\Megamenu;
use App\Pcategory;
use App\ProductImage;
use App\Product;
use App\ProductOrder;
use Validator;
use Session;
use Illuminate\Support\Facades\Http;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;


class ProductController extends Controller
{
    public function index(Request $request)
    {
        $lang = Language::where('code', $request->language)->first();

        $lang_id = $lang->id;
        $data['products'] = Product::where('language_id', $lang_id)->orderBy('id', 'DESC')->get();
        $data['lang_id'] = $lang_id;
        return view('admin.product.index',$data);
    }



    public function type(Request $request) {
        $data['digitalCount'] = Product::where('type', 'digital')->count();
        $data['physicalCount'] = Product::where('type', 'physical')->count();
        return view('admin.product.type', $data);
    }


    public function create(Request $request)
    {
        $lang = Language::where('code', $request->language)->first();
        $abx = $lang->basic_extra;
        $categories = Pcategory::where('status',1)->get();
        return view('admin.product.create',compact('categories','abx'));
    }


    public function uploadUpdate(Request $request, $id)
    {
        $img = $request->file('file');
        $allowedExts = array('jpg', 'png', 'jpeg');

        $rules = [
            'file' => [
                function ($attribute, $value, $fail) use ($img, $allowedExts) {
                    if (!empty($img)) {
                        $ext = $img->getClientOriginalExtension();
                        if (!in_array($ext, $allowedExts)) {
                            return $fail("Only png, jpg, jpeg image is allowed");
                        }
                    }
                },
            ],
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $validator->getMessageBag()->add('error', 'true');
            return response()->json(['errors' => $validator->errors(), 'id' => 'slider']);
        }

        $product = Product::findOrFail($id);
        if ($request->hasFile('file')) {
            $filename = time() . '.' . $img->getClientOriginalExtension();
            $request->file('file')->move('assets/front/img/product/featured/', $filename);
            @unlink('assets/front/img/product/featured/' . $product->feature_image);
            $product->feature_image = $filename;
            $product->save();
        }

        return response()->json(['status' => "success", "image" => "Product image", 'product' => $product]);
    }


    public function getCategory($langid)
    {
        $category = Pcategory::where('language_id', $langid)->get();
        return $category;
    }


    public function store(Request $request)
    {
        $slug = make_slug($request->title);
        $bex = BasicExtra::firstOrFail();

        $sliders = !empty($request->slider) ? explode(',', $request->slider) : [];
        $featredImg = $request->featured_image;
        $extFeatured = pathinfo($featredImg, PATHINFO_EXTENSION);
        $allowedExts = array('jpg', 'png', 'jpeg');

        $rules = [];

        $rules = [
            'slider' => 'required',
            'language_id' => 'required',
            'title' => [
                'required',
                'max:255',
                function ($attribute, $value, $fail) use ($slug) {
                    $products = Product::all();
                    foreach ($products as $key => $product) {
                        if (strtolower($slug) == strtolower($product->slug)) {
                            $fail('The title field must be unique.');
                        }
                    }
                }
            ],
            'category_id' => 'required',
            'featured_image' => 'required',
            'status' => 'required'
        ];

        if ($bex->catalog_mode == 0) {
            $rules['current_price'] = 'required|numeric';
            $rules['previous_price'] = 'nullable|numeric';
        }

        if ($request->filled('slider')) {
            $rules['slider'] = [
                function ($attribute, $value, $fail) use ($sliders, $allowedExts) {
                    foreach ($sliders as $key => $slider) {
                        $extSlider = pathinfo($slider, PATHINFO_EXTENSION);
                        if (!in_array($extSlider, $allowedExts)) {
                            return $fail("Only png, jpg, jpeg images are allowed");
                        }
                    }
                }
            ];
        }

        if ($request->filled('featured_image')) {
            $rules['featured_image'] = [
                function ($attribute, $value, $fail) use ($extFeatured, $allowedExts) {
                    if (!in_array($extFeatured, $allowedExts)) {
                        return $fail("Only png, jpg, jpeg image is allowed");
                    }
                }
            ];
        }

        // if product type is 'physical'
        if ($request->type == 'physical') {
            $rules['stock'] = 'required';
            $rules['sku'] = 'required|unique:products';
        }

        // if product type is 'digital'
        if ($request->type == 'digital') {
            $rules['file_type'] = 'required';

            // if 'file upload' is chosen
            if ($request->has('file_type') && $request->file_type == 'upload') {
                $allowedExts = array('zip');
                $rules['download_file'] = [
                    'required',
                    function ($attribute, $value, $fail) use ($request, $allowedExts) {
                        $file = $request->file('download_file');
                        $ext = $file->getClientOriginalExtension();
                        if (!in_array($ext, $allowedExts)) {
                            return $fail("Only zip file is allowed");
                        }
                    }
                ];
            }
            // if 'file donwload link' is chosen
            elseif ($request->has('file_type') && $request->file_type == 'link') {
                $rules['download_link'] = 'required';
            }
        }

        $messages = [
            'language_id.required' => 'The language field is required',
            'category_id.required' => 'Category is required'
        ];


        $validator = Validator::make($request->all(), $rules, $messages);
        if ($validator->fails()) {
            $errmsgs = $validator->getMessageBag()->add('error', 'true');
            return response()->json($validator->errors());
        }

        $in = $request->all();
        $in['language_id'] = $request->language_id;
        $in['slug'] = $slug;

        // store featured image
        $filename = uniqid() .'.'. $extFeatured;
        @copy($featredImg, 'assets/front/img/product/featured/' . $filename);
        $in['feature_image'] = $filename;

        // if the type is digital && 'upload file' method is selected, then store the downloadable file
        if ($request->type == 'digital' && $request->file_type == 'upload') {
            if ($request->hasFile('download_file')) {
                $digitalFile = $request->file('download_file');
                $filename = $slug . '-' . uniqid() . "." . $digitalFile->extension();
                $directory = 'core/storage/digital_products/';
                @mkdir($directory, 0775, true);
                $digitalFile->move($directory, $filename);

                $in['download_file'] = $filename;
            }
        }

        if ($request->type == 'physical') {
            $in['stock'] = $request->stock;
            $in['sku'] = $request->sku;
        }

        $in['description'] = str_replace(url('/') . '/assets/front/img/', "{base_url}/assets/front/img/", $request->description);

        $product = Product::create($in);

        foreach ($sliders as $key => $slider) {
            $extSlider = pathinfo($slider, PATHINFO_EXTENSION);
            $filename = uniqid() .'.'. $extSlider;
            @copy($slider, 'assets/front/img/product/sliders/' . $filename);

            $pi = new ProductImage;
            $pi->product_id = $product->id;
            $pi->image = $filename;
            $pi->save();
        }

        Session::flash('success', 'Product added successfully!');
        return "success";
    }


    public function edit(Request $request, $id)
    {
        $lang = Language::where('code', $request->language)->first();

        $abx = $lang->basic_extra;
        $categories = $lang->pcategories()->where('status',1)->get();
        $data = Product::findOrFail($id);
        return view('admin.product.edit',compact('categories','data','abx'));
    }

    public function images($portid)
    {
        $images = ProductImage::select('image')->where('product_id', $portid)->get();
        $convImages = [];

        foreach ($images as $key => $image) {
            $convImages[] = url("assets/front/img/product/sliders/$image->image");
        }

        return $convImages;
    }

    public function update(Request $request)
    {
        $slug = make_slug($request->title);
        $product = Product::findOrFail($request->product_id);
        $productId = $product->id;

        $sliders = !empty($request->slider) ? explode(',', $request->slider) : [];
        $featredImg = $request->featured_image;
        $extFeatured = pathinfo($featredImg, PATHINFO_EXTENSION);
        $allowedExts = array('jpg', 'png', 'jpeg');

        $rules = [
            'slider' => 'required',
            'title' => [
                'required',
                'max:255',
                function ($attribute, $value, $fail) use ($slug, $productId) {
                    $products = Product::all();
                    foreach ($products as $key => $product) {
                        if ($product->id != $productId && strtolower($slug) == strtolower($product->slug)) {
                            $fail('The title field must be unique.');
                        }
                    }
                }
            ],
            'category_id' => 'required',
            'status' => 'required'
        ];

        $bex = BasicExtra::firstOrFail();
        if ($bex->catalog_mode == 0) {
            $rules['current_price'] = 'required|numeric';
            $rules['previous_price'] = 'nullable|numeric';
        }

        if ($request->filled('slider')) {
            $rules['slider'] = [
                function ($attribute, $value, $fail) use ($sliders, $allowedExts) {
                    foreach ($sliders as $key => $slider) {
                        $extSlider = pathinfo($slider, PATHINFO_EXTENSION);
                        if (!in_array($extSlider, $allowedExts)) {
                            return $fail("Only png, jpg, jpeg images are allowed");
                        }
                    }
                }
            ];
        }

        if ($request->filled('featured_image')) {
            $rules['featured_image'] = [
                function ($attribute, $value, $fail) use ($extFeatured, $allowedExts) {
                    if (!in_array($extFeatured, $allowedExts)) {
                        return $fail("Only png, jpg, jpeg image is allowed");
                    }
                }
            ];
        }

        // if product type is 'physical'
        if ($product->type == 'physical') {
            $rules['stock'] = 'required';
            $rules['sku'] = [
                'required',
                Rule::unique('products')->ignore($request->product_id),
            ];
        }

        // if product type is 'digital'
        if ($product->type == 'digital') {
            $rules['file_type'] = 'required';

            // if 'file upload' is chosen
            if ($request->has('file_type') && $request->file_type == 'upload') {

                if (empty($product->download_file)) {
                    $rules['download_file'][] = 'required';
                }
                $rules['download_file'][] = function ($attribute, $value, $fail) use ($product, $request) {
                    $allowedExts = array('zip');
                    if ($request->hasFile('download_file')) {
                        $file = $request->file('download_file');
                        $ext = $file->getClientOriginalExtension();
                        if (!in_array($ext, $allowedExts)) {
                            return $fail("Only zip file is allowed");
                        }
                    }
                };
            }
            // if 'file donwload link' is chosen
            elseif ($request->has('file_type') && $request->file_type == 'link') {
                $rules['download_link'] = 'required';
            }
        }

        $messages = [
            'category_id.required' => 'Service is required',
            'description.min' => 'Description is required'
        ];



        $validator = Validator::make($request->all(), $rules, $messages);
        if ($validator->fails()) {
            $errmsgs = $validator->getMessageBag()->add('error', 'true');
            return response()->json($validator->errors());
        }

        $in = $request->all();
        $in['slug'] = $slug;

        // if the type is digital && 'link' method is selected, then store the downloadable file
        if ($product->type == 'digital' && $request->file_type == 'link') {
            @unlink('core/storage/digital_products/' . $product->download_file);
            $in['download_file'] = NULL;
        }

        // if the type is digital && 'upload file' method is selected, then store the downloadable file
        if ($product->type == 'digital' && $request->file_type == 'upload') {
            if ($request->hasFile('download_file')) {
                @unlink('core/storage/digital_products/' . $product->download_file);

                $digitalFile = $request->file('download_file');
                $filename = $slug . '-' . uniqid() . "." . $digitalFile->extension();
                $directory = 'core/storage/digital_products/';
                @mkdir($directory, 0775, true);
                $digitalFile->move($directory, $filename);

                $in['download_file'] = $filename;
                $in['download_link'] = NULL;
            }
        }
        $in['description'] = str_replace(url('/') . '/assets/front/img/', "{base_url}/assets/front/img/", $request->description);

        // update featured image
        if ($request->filled('featured_image')) {
            @unlink('assets/front/img/product/featured/' . $product->feature_image);
            $filename = uniqid() .'.'. $extFeatured;
            @copy($featredImg, 'assets/front/img/product/featured/' . $filename);
            $in['feature_image'] = $filename;
        }

        $product->fill($in)->save();

        // copy the sliders first
        $fileNames = [];
        foreach ($sliders as $key => $slider) {
            $extSlider = pathinfo($slider, PATHINFO_EXTENSION);
            $filename = uniqid() .'.'. $extSlider;
            @copy($slider, 'assets/front/img/product/sliders/' . $filename);
            $fileNames[] = $filename;
        }

        // delete & unlink previous slider images
        $pis = ProductImage::where('product_id', $product->id)->get();
        foreach ($pis as $key => $pi) {
            @unlink('assets/front/img/product/sliders/' . $pi->image);
            $pi->delete();
        }

        // store new slider images
        foreach ($fileNames as $key => $fileName) {
            $pi = new ProductImage;
            $pi->product_id = $product->id;
            $pi->image = $fileName;
            $pi->save();
        }

        Session::flash('success', 'Product updated successfully!');
        return "success";
    }


    public function deleteFromMegaMenu($product) {
        // unset service from megamenu for service_category = 1
        $megamenu = Megamenu::where('language_id', $product->language_id)->where('category', 1)->where('type', 'products');
        if ($megamenu->count() > 0) {
            $megamenu = $megamenu->first();
            $menus = json_decode($megamenu->menus, true);
            $catId = $product->category->id;
            if (is_array($menus) && array_key_exists("$catId", $menus)) {
                if (in_array($product->id, $menus["$catId"])) {
                    $index = array_search($product->id, $menus["$catId"]);
                    unset($menus["$catId"]["$index"]);
                    $menus["$catId"] = array_values($menus["$catId"]);
                    if (count($menus["$catId"]) == 0) {
                        unset($menus["$catId"]);
                    }
                    $megamenu->menus = json_encode($menus);
                    $megamenu->save();
                }
            }
        }
    }


    public function feature(Request $request)
    {
        $product = Product::findOrFail($request->product_id);
        $product->is_feature = $request->is_feature;
        $product->save();

        if ($request->is_feature == 1) {
            Session::flash('success', 'Product featured successfully!');
        } else {
            Session::flash('success', 'Product unfeatured successfully!');
        }
        return back();
    }


    public function delete(Request $request)
    {
        $product = Product::findOrFail($request->product_id);

        foreach ($product->product_images as $key => $pi) {
            @unlink('assets/front/img/product/sliders/' . $pi->image);
            $pi->delete();
        }

        @unlink('assets/front/img/product/featured/' . $product->feature_image);
        @unlink('core/storage/digital_products/' . $product->download_file);

        $this->deleteFromMegaMenu($product);

        $product->delete();

        Session::flash('success', 'Product deleted successfully!');
        return back();
    }


    public function bulkDelete(Request $request)
    {
        $ids = $request->ids;

        foreach ($ids as $id) {
            $product = Product::findOrFail($id);
            foreach ($product->product_images as $key => $pi) {
                @unlink('assets/front/img/product/sliders/' . $pi->image);
                $pi->delete();
            }
        }

        foreach ($ids as $id) {
            $product = product::findOrFail($id);
            @unlink('assets/front/img/product/featured/' . $product->feature_image);

            $this->deleteFromMegaMenu($product);

            $product->delete();
        }

        Session::flash('success', 'Product deleted successfully!');
        return "success";
    }


    public function populerTag(Request $request)
    {
        $lang = Language::where('code', $request->language)->first();
        $lang_id = $lang->id;
        $data = BE::where('language_id',$lang_id)->first();
        return view('admin.product.tag.index',compact('data'));
    }

    public function populerTagupdate(Request $request)
    {
        $rules = [
            'language_id' => 'required',
            'popular_tags' => 'required',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            $errmsgs = $validator->getMessageBag()->add('error', 'true');
            return response()->json($validator->errors());
        }

        $lang = Language::where('code', $request->language_id)->first();
        $be = BE::where('language_id',$lang->id)->first();
        $be->popular_tags = $request->popular_tags;
        $be->save();
        Session::flash('success', 'Populer tags update successfully!');
        return "success";
    }

    public function paymentStatus(Request $request) {
        $order = ProductOrder::find($request->order_id);
        $order->payment_status = $request->payment_status;
        $order->save();

        // $user = User::findOrFail($po->user_id);
        $be = BE::first();
        $sub = 'Payment Status Updated';

        $to = $order->billing_email;
        $fname = $order->billing_fname;

         // Send Mail to Buyer
         $mail = new PHPMailer(true);
         if ($be->is_smtp == 1) {
             try {
                 $mail->isSMTP();
                 $mail->Host       = $be->smtp_host;
                 $mail->SMTPAuth   = true;
                 $mail->Username   = $be->smtp_username;
                 $mail->Password   = $be->smtp_password;
                 $mail->SMTPSecure = $be->encryption;
                 $mail->Port       = $be->smtp_port;

                 //Recipients
                 $mail->setFrom($be->from_mail, $be->from_name);
                 $mail->addAddress($to, $fname);

                 // Content
                 $mail->isHTML(true);
                 $mail->Subject = $sub;
                 $mail->Body    = 'Hello <strong>' . $fname . '</strong>,<br/>Your payment status is changed to '.$request->payment_status.'.<br/>Thank you.';
                 $mail->send();
             } catch (Exception $e) {
                 // die($e->getMessage());
             }
         } else {
             try {

                 //Recipients
                 $mail->setFrom($be->from_mail, $be->from_name);
                 $mail->addAddress($to, $fname);


                 // Content
                 $mail->isHTML(true);
                 $mail->Subject = $sub ;
                 $mail->Body    = 'Hello <strong>' . $fname . '</strong>,<br/>Your payment status is changed to '.$request->payment_status.'.<br/>Thank you.';

                 $mail->send();
             } catch (Exception $e) {
                 // die($e->getMessage());
             }
         }

        Session::flash('success', 'Payment Status updated!');
        return back();
    }

    public function settings() {
        $data['abex'] = BasicExtra::first();
        return view('admin.product.settings', $data);
    }

    public function updateSettings(Request $request) {
        $bexs = BasicExtra::all();
        foreach($bexs as $bex) {
            $bex->product_rating_system = $request->product_rating_system;
            $bex->product_guest_checkout = $request->product_guest_checkout;
            $bex->is_shop = $request->is_shop;
            $bex->catalog_mode = $request->catalog_mode;
            $bex->tax = $request->tax ? $request->tax : 0.00;
            $bex->save();
        }

        $request->session()->flash('success', 'Settings updated successfully!');
        return back();
    }








//for synch prods

public function synchroniserProducts(Request $request)
{
    $apiUrl = 'http://51.83.131.79/hdcomercialeco/';
    $response = Http::get($apiUrl . 'ListeProduits_Links');
    $produitsApi = $response->json();

    // Retrieve all existing products and organize them by slug
    $barcodes = collect($produitsApi)->pluck('Codeabarre')->toArray();

    $notExistingProducts = Product::whereNotIn('slug', $barcodes)
        ->get()
        ->keyBy('slug');

    foreach ($notExistingProducts as $notExistingProduct) {
        // Set is_publish to 0 for products not found in the API list
        $notExistingProduct->is_publish = 0;
        $notExistingProduct->save();
    }

    $existingProducts = Product::whereIn('slug', $barcodes)
        ->get()
        ->keyBy('slug');

    $lang = Language::where('is_default', 1)->first();
    $lang_id = $lang->id;

    foreach ($existingProducts as $existingProduct) {
        // Set is_publish to 1 for products found in the API list
        $existingProduct->is_publish = 1;
        $existingProduct->save();
    }

    // Store hashes of existing photos to avoid duplication
    $existingPhotos = Product::pluck('feature_image')->toArray();
    $existingPhotoHashes = [];
    foreach ($existingPhotos as $photo) {
        $photoPath = public_path('assets/front/img/product/featured/' . $photo);
        if (file_exists($photoPath) && is_file($photoPath)) {
            $existingPhotoHashes[md5_file($photoPath)] = $photo;
        }
    }

    foreach ($produitsApi as $produitApi) {
        $name = $produitApi['Libellé'];
        $barcode = $produitApi['Codeabarre'];
        $apiPhoto = $produitApi['MyPhoto'];
        $apiFile = $produitApi['MyFile'];

        // Check if the product already exists by slug
        if (!isset($existingProducts[$barcode])) {
            $newProduct = new Product();

            // Check if the photo URL is not empty and is a valid URL
            if (!empty($apiPhoto) && filter_var($apiPhoto, FILTER_VALIDATE_URL)) {
                // Download the photo content
                $photoContent = @file_get_contents($apiPhoto);
                if ($photoContent !== false) {
                    // Compute the MD5 hash of the photo content
                    $photoHash = md5($photoContent);

                    if (isset($existingPhotoHashes[$photoHash])) {
                        // Use existing photo
                        $newProduct['feature_image'] = $existingPhotoHashes[$photoHash];
                    } else {
                        // Save new photo
                        $extFeatured = pathinfo(parse_url($apiPhoto, PHP_URL_PATH), PATHINFO_EXTENSION);
                        $filename = uniqid() . '.' . $extFeatured;
                        $filePath = public_path('assets/front/img/product/featured/' . $filename);

                        // Ensure the directory exists
                        if (!file_exists(dirname($filePath))) {
                            mkdir(dirname($filePath), 0755, true);
                        }

                        file_put_contents($filePath, $photoContent);
                        $newProduct['feature_image'] = $filename;

                        // Store hash of the new photo
                        $existingPhotoHashes[$photoHash] = $filename;
                    }
                }
            }





            if (!empty($apiFile) && filter_var($apiPhoto, FILTER_VALIDATE_URL)) {
                // Download the photo content
                $fileContent = @file_get_contents($apiFile);
                if ($fileContent !== false) {
                    // Compute the MD5 hash of the photo content
                    $fileHash = md5($fileContent);

                    if (isset($existingFileHashes[$fileContent])) {
                        // Use existing photo
                        $newProduct['pdf_file'] = $existingFileHashes[$fileHash];
                    } else {
                        // Save new photo
                        $extFeatured = pathinfo(parse_url($apiFile, PHP_URL_PATH), PATHINFO_EXTENSION);
                        $filename = uniqid() . '.' . $extFeatured;
                        $filePath = public_path('assets/front/files/products/' . $filename);

                        // Ensure the directory exists
                        if (!file_exists(dirname($filePath))) {
                            mkdir(dirname($filePath), 0755, true);
                        }

                        file_put_contents($filePath, $photoContent);
                        $newProduct['pdf_file'] = $filename;

                        // Store hash of the new photo
                        $existingPhotoHashes[$photoHash] = $filename;
                    }
                }
            }






            $newProduct->title = $name;
            $newProduct->slug = $barcode;
            $newProduct->language_id = $lang->id;
            $newProduct->stock = 10000;
            $newProduct->is_publish = 1;
            // Set other properties accordingly based on your product model
            $newProduct->save();
        } else {
            $matchingProduct = $existingProducts[$barcode];
            if ($matchingProduct->title != $name) {
                $matchingProduct->title = $name;
            }

            // Handle missing photo for existing product
            if (empty($matchingProduct['feature_image']) && !empty($apiPhoto) && filter_var($apiPhoto, FILTER_VALIDATE_URL)) {
                $photoContent = @file_get_contents($apiPhoto);
                if ($photoContent !== false) {
                    $photoHash = md5($photoContent);

                    if (!isset($existingPhotoHashes[$photoHash])) {
                        $extFeatured = pathinfo(parse_url($apiPhoto, PHP_URL_PATH), PATHINFO_EXTENSION);
                        $filename = uniqid() . '.' . $extFeatured;
                        $filePath = public_path('assets/front/img/product/featured/' . $filename);

                        if (!file_exists(dirname($filePath))) {
                            mkdir(dirname($filePath), 0755, true);
                        }

                        file_put_contents($filePath, $photoContent);
                        $matchingProduct['feature_image'] = $filename;
                        $existingPhotoHashes[$photoHash] = $filename;
                    } else {
                        $matchingProduct['feature_image'] = $existingPhotoHashes[$photoHash];
                    }
                }
            }

            // Handle missing PDF file for existing product
            if (empty($matchingProduct['pdf_file']) && !empty($apiFile) && filter_var($apiFile, FILTER_VALIDATE_URL)) {
                $fileContent = @file_get_contents($apiFile);
                if ($fileContent !== false) {
                    $fileHash = md5($fileContent);

                    if (!isset($existingFileHashes[$fileHash])) {
                        $extFile = pathinfo(parse_url($apiFile, PHP_URL_PATH), PATHINFO_EXTENSION);
                        $filename = uniqid() . '.' . $extFile;
                        $filePath = public_path('assets/front/files/products/' . $filename);

                        if (!file_exists(dirname($filePath))) {
                            mkdir(dirname($filePath), 0755, true);
                        }

                        file_put_contents($filePath, $fileContent);
                        $matchingProduct['pdf_file'] = $filename;
                        $existingFileHashes[$fileHash] = $filename;
                    } else {
                        $matchingProduct['pdf_file'] = $existingFileHashes[$fileHash];
                    }
                }
            }

            $matchingProduct->save();
        }
    }

    $data['products'] = Product::where('language_id', $lang_id)->orderBy('id', 'DESC')->get();
    $data['lang_id'] = $lang_id;

    return view('admin.product.index', $data);
}




}
