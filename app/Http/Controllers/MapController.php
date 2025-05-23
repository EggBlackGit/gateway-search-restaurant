<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Facades\Cache;

class MapController extends Controller
{
    public function searchRestaurantsNew(Request $request)
    {
        $keyword = $request->input('keyword');
        if (!is_string($keyword) || trim($keyword) === '') {
            return response()->json(['error' => 'กรุณากรอกคำค้นหา'], 422);
        }
        $apiKey = env('GOOGLE_MAPS_API_KEY');

        try {
            // call to google textsearch เพื่อดึงข้อมูลเบิ้องต้น
            $response = Http::get('https://maps.googleapis.com/maps/api/place/textsearch/json', [
                'query' => $keyword,
                'type' => 'restaurant', // เฉพาะร้านอาหาร
                'key' => $apiKey,
            ]);

            $results = $response->json()["results"];
            // เอาไว้เก็บข้อมูลที่จะ response ไปหา front end
            $responsesData = [];
            foreach ($results as $result){
                $placeId = $result["place_id"];
                if (!is_string($placeId) || trim($placeId) === '') {
                    continue;
                }
                // สร้าง key cache place_detail_ + placeId
                $cacheKey = 'place_detail_' . $placeId;
                $cached = Cache::get($cacheKey);
                // ถ้าเจอ cache จะนำข้อมูลใน cache ไปใช้ โดยใส่ไว้ในตัวแปร $responsesData[]
                if ($cached) {
                    $responsesData[] = $cached;
                    continue;
                }
                // call to google details ดึงข้อมูลรายละเอียดของสถานที่นั้นๆ
                $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/place/details/json', [
                    'place_id' => $placeId,
                    'fields' => 'name,formatted_address,photos,geometry,formatted_phone_number,opening_hours,price_level,rating',
                    'language' => 'th',
                    'key' => $apiKey,
                ]);

                if (!$response->successful() || $response->json('status') !== 'OK') {
                    $responsesData[] = [
                        'place_id' => $placeId,
                        'error' => 'Failed to fetch place details',
                    ];
                    continue;
                }

                $place = $response->json('result');
                $photoUrl = null;

                if (!empty($place['photos'][0]['photo_reference'])) {
                    $photoRef = $place['photos'][0]['photo_reference'];
                    $filename = $placeId . '_0.jpg';
                    $path = 'place_photos/' . $filename;
                    // เช็คว่ามีภาพรึยัง ถ้ายังจะไป call google place photo เพื่อดึงภาพออกมาใช้ และเก็บไว้ที่ public -> storage -> place_photos
                    if (!Storage::disk('public')->exists($path)) {
                        $photoRes = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/place/photo', [
                            'maxwidth' => 400,
                            'photoreference' => $photoRef,
                            'key' => $apiKey,
                        ]);

                        if ($photoRes->successful() && str_starts_with($photoRes->header('Content-Type'), 'image')) {
                            Storage::disk('public')->put($path, $photoRes->body());
                        }
                    }
                    // ถ้ามีภาพแล้วนำไปใช้เลย
                    $photoUrl = asset('storage/' . $path);
                }
                // แพ็คข้อมูลเพื่อจะไป response ให้ front end
                $data = [
                    'place_id' => $placeId,
                    'name' => $place['name'] ?? '',
                    'address' => $place['formatted_address'] ?? '',
                    'location' => $place['geometry']['location'] ?? null,
                    'photo_url' => $photoUrl,
                    'phone_number' => $this->formatPhoneNumber($place['formatted_phone_number'] ?? ''),
                    'opening_hours' => $place['opening_hours'] ?? 'ไม่ระบุเวลาเปิด-ปิด',
                    'rating' => $place['rating'] ?? 0,
                    'price_level' => $this->formatPriceLevel($place['price_level'] ?? null)
                ];
                // เก็บข้อมูลเข้า cache
                Cache::put($cacheKey, $data, now()->addHours(6));

                $responsesData[] = $data;
            }
        } catch (\Exception $e) {
            // log error
            \Log::error('Error in searchRestaurantsNew: ' . $e->getMessage());
            return response()->json(['error' => 'Internal Server Error', 'message' => $e->getMessage()], 500);
        }

        return response()->json($responsesData);
    }
    // map price level
    function formatPriceLevel($level) {
        $map = [
            0 => 'ฟรี',
            1 => 'ราคาถูก',
            2 => 'ราคาปานกลาง',
            3 => 'ราคาค่อนข้างแพง',
            4 => 'แพงมาก'
        ];
        return $map[$level] ?? 'ไม่ระบุราคา';
    }

    function formatPhoneNumber($input) {
        // ถ้าไม่มีค่าก็ return ออกไปเลย
        if (!$input){
            return $input;
        }
        // ลบช่องว่างและตัวอักษรที่ไม่ใช่ตัวเลขออก
        $digits = preg_replace('/\D/', '', $input);

        // เช็คว่ามีตัวเลขครบ 10 ตัวไหม
        if (strlen($digits) !== 10) {
            return 'เบอร์โทรไม่ถูกต้อง';
        }

        // แบ่งและต่อ string เป็น xxx-xxx-xxxx
        $part1 = substr($digits, 0, 3);
        $part2 = substr($digits, 3, 3);
        $part3 = substr($digits, 6, 4);

        return $part1 . '-' . $part2 . '-' . $part3;
    }
}