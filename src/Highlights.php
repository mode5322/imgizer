<?php

namespace App;

final class Highlights
{
    private static function find(array $record, array $tags): ?array
    {
        foreach ($tags as $tag) {
            foreach ($record as $key => $value) {
                $pos = strrpos($key, ':');
                $name = $pos === false ? $key : substr($key, $pos + 1);
                $name = preg_replace('/_\d+$/', '', $name);
                if (strcasecmp($name, $tag) === 0) {
                    return ['group' => $pos === false ? '' : substr($key, 0, $pos), 'value' => $value];
                }
            }
        }
        return null;
    }

    private static function value(array $record, array $tags): ?string
    {
        $found = self::find($record, $tags);
        if ($found === null) {
            return null;
        }
        $value = $found['value'];
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }

    private static function parseCoordinate(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (preg_match('/^([0-9.]+)/', $value, $m)) {
            $number = (float) $m[1];
            if (preg_match('/([0-9.]+)\s*deg\s*([0-9.]+)?\'?\s*([0-9.]+)?"?\s*([NSEW])/i', $value, $dm)) {
                $deg = (float) $dm[1];
                $min = isset($dm[2]) && $dm[2] !== '' ? (float) $dm[2] : 0;
                $sec = isset($dm[3]) && $dm[3] !== '' ? (float) $dm[3] : 0;
                $number = $deg + $min / 60 + $sec / 3600;
                if (strtoupper($dm[4]) === 'S' || strtoupper($dm[4]) === 'W') {
                    $number = -$number;
                }
            } elseif (preg_match('/([NSEW])\s*$/', $value, $dir)) {
                if (in_array(strtoupper($dir[1]), ['S', 'W'], true)) {
                    $number = -abs($number);
                }
            }
            return $number;
        }
        return null;
    }

    public static function build(array $record, array $dims): array
    {
        $camera = [];
        $map = [
            'Manufacturer' => ['Make'],
            'Model' => ['Model'],
            'Lens' => ['LensModel', 'LensID', 'Lens'],
            'Focal length' => ['FocalLength'],
            'Focal length (35mm)' => ['FocalLength35efl'],
            'Exposure time' => ['ExposureTime'],
            'Shutter speed' => ['ShutterSpeed'],
            'Aperture' => ['FNumber', 'Aperture'],
            'ISO sensitivity' => ['ISO'],
            'Exposure program' => ['ExposureProgram'],
            'Exposure compensation' => ['ExposureCompensation'],
            'Flash' => ['Flash'],
            'Metering mode' => ['MeteringMode'],
            'White balance' => ['WhiteBalance'],
            'Date taken' => ['DateTimeOriginal'],
            'Date created' => ['CreateDate'],
            'Date modified' => ['ModifyDate'],
            'Software' => ['Software'],
            'Artist / owner' => ['Artist', 'OwnerName'],
            'Copyright' => ['Copyright'],
            'Orientation' => ['Orientation'],
            'Color space' => ['ColorSpace'],
            'Dimensions' => ['ImageSize'],
            'Megapixels' => ['Megapixels'],
            'Camera serial number' => ['SerialNumber', 'BodySerialNumber'],
            'Lens serial number' => ['LensSerialNumber'],
            'Host computer' => ['HostComputer'],
        ];
        foreach ($map as $label => $tags) {
            $v = self::value($record, $tags);
            if ($v !== null && $v !== '') {
                $camera[] = ['label' => $label, 'value' => $v];
            }
        }

        $lat = self::parseCoordinate(self::value($record, ['GPSLatitude']));
        $lng = self::parseCoordinate(self::value($record, ['GPSLongitude']));
        $position = self::value($record, ['GPSPosition']);
        if ($position && ($lat === null || $lng === null)) {
            if (preg_match('/([0-9.\-]+)\s*([NS])?,?\s*([0-9.\-]+)\s*([EW])?/', $position, $m)) {
                $lat = self::parseCoordinate(($m[1] ?? '') . ' ' . ($m[2] ?? ''));
                $lng = self::parseCoordinate(($m[3] ?? '') . ' ' . ($m[4] ?? ''));
            }
        }

        $gps = ['available' => $lat !== null && $lng !== null, 'lat' => $lat, 'lng' => $lng];
        if ($gps['available']) {
            $alt = self::value($record, ['GPSAltitude']);
            $gps['altitude'] = $alt;
            $gps['datetime'] = self::value($record, ['GPSDateTime']);
            $gps['direction'] = self::value($record, ['GPSImgDirection']);
            $gps['position'] = $position ?? sprintf('%.6f, %.6f', $lat, $lng);
            $coords = sprintf('%F,%F', $lat, $lng);
            $gps['links'] = [
                'google' => 'https://www.google.com/maps?q=' . $coords,
                'osm' => 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=16/' . $lat . '/' . $lng,
                'bing' => 'https://www.bing.com/maps?cp=' . $lat . '~' . $lng . '&lvl=16',
                'yandex' => 'https://yandex.com/maps/?ll=' . $lng . '%2C' . $lat . '&z=16',
            ];
        }

        $privacyTags = [
            'Location (GPS)' => ['GPSLatitude'],
            'Artist / owner name' => ['Artist', 'OwnerName'],
            'Copyright' => ['Copyright'],
            'Camera serial number' => ['SerialNumber', 'BodySerialNumber'],
            'Lens serial number' => ['LensSerialNumber'],
            'User comment' => ['UserComment', 'Comment'],
            'Description' => ['ImageDescription'],
            'XP comment' => ['XPComment', 'XPTitle', 'XPSubject', 'XPAuthor'],
            'Host / user' => ['HostComputer'],
            'Email' => ['Email', 'ContactInfo'],
            'Editing software' => ['Software'],
        ];
        $privacy = [];
        foreach ($privacyTags as $label => $tags) {
            $v = self::value($record, $tags);
            if ($v !== null && $v !== '') {
                $privacy[] = ['label' => $label, 'value' => mb_substr($v, 0, 200)];
            }
        }

        $editors = ['photoshop', 'gimp', 'lightroom', 'capture one', 'paint.net', 'pixelmator',
            'affinity', 'snapseed', 'picsart', 'canva', 'instagram', 'whatsapp', 'telegram',
            'pixlr', 'vsco', 'facetune', 'meitu', 'adobe', 'camera raw', 'darktable', 'rawtherapee'];
        $editing = [];
        $software = self::value($record, ['Software']);
        if ($software) {
            foreach ($editors as $editor) {
                if (stripos($software, $editor) !== false) {
                    $editing[] = ['label' => 'Editing software', 'value' => $software];
                    break;
                }
            }
        }
        $editingTags = [
            'XMP edit history' => ['HistoryWhen', 'HistorySoftwareAgent', 'HistoryAction'],
            'Document ID' => ['InstanceID', 'DocumentID', 'OriginalDocumentID'],
            'Extended XMP' => ['HasExtendedXMP'],
        ];
        foreach ($editingTags as $label => $tags) {
            $v = self::value($record, $tags);
            if ($v !== null && $v !== '') {
                $editing[] = ['label' => $label, 'value' => mb_substr($v, 0, 200)];
            }
        }

        $hasExif = false;
        foreach (array_keys($record) as $key) {
            if (str_starts_with($key, 'EXIF:') || str_starts_with($key, 'IFD0:') || str_starts_with($key, 'ExifIFD:')) {
                $hasExif = true;
                break;
            }
        }

        return [
            'camera' => $camera,
            'gps' => $gps,
            'privacy' => $privacy,
            'editing' => $editing,
            'has_exif' => $hasExif,
        ];
    }
}
