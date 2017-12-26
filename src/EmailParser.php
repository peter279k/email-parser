<?php

/*
 * Email Parser
 * https://github.com/ivopetkov/email-parser
 * Copyright 2017, Ivo Petkov
 * Free to use under the MIT license.
 */

namespace IvoPetkov;

/**
 * 
 */
class EmailParser
{

    /**
     * 
     * @param string $email
     * @return array
     */
    public function parse(string $email): array
    {
        $result = [];
        $parts = explode("\r\n\r\n", $email, 2);
        $headers = $this->parseHeaders($parts[0]);
        $result['deliveryDate'] = strtotime($this->getHeaderValue($headers, 'Delivery-date'));
        $result['date'] = strtotime($this->getHeaderValue($headers, 'Date'));
        $result['subject'] = $this->decodeMIMEEncodedText($this->getHeaderValue($headers, 'Subject'));
        $result['to'] = $this->parseEmailAdress($this->getHeaderValue($headers, 'To'));
        $result['from'] = $this->parseEmailAdress($this->getHeaderValue($headers, 'From'));
        $result['replyTo'] = $this->parseEmailAdress($this->getHeaderValue($headers, 'Reply-To'));

        $result['text'] = '';
        $result['html'] = '';
        $result['attachments'] = [];
        $inlineAttachments = [];

        $bodyParts = $this->getBodyParts($email);
        foreach ($bodyParts as $bodyPart) {
            $contentTypeData = $this->getHeaderValueAndOptions($bodyPart[0], 'Content-Type');
            if ($contentTypeData[0] === 'text/plain') {
                $result['text'] = $this->decodeBodyPart($bodyPart[0], $bodyPart[1]);
            } elseif ($contentTypeData[0] === 'text/html') {
                $result['html'] = $this->decodeBodyPart($bodyPart[0], $bodyPart[1]);
            } elseif ($contentTypeData[0] === '') {
                if (strlen($result['text']) === 0) {
                    $result['text'] = $this->decodeBodyPart($bodyPart[0], $bodyPart[1]);
                }
            } else {
                $attachmentData = [];
                $attachmentData['contentType'] = $contentTypeData[0];
                $attachmentData['name'] = isset($contentTypeData[1]['name']) ? $this->decodeMIMEEncodedText($contentTypeData[1]['name']) : '';
                $attachmentData['id'] = trim($this->getHeaderValue($bodyPart[0], 'Content-ID'), '<>');
                $attachmentData['base64Content'] = base64_encode($this->decodeBodyPart($bodyPart[0], $bodyPart[1]));
                $contentDisposition = strtolower($this->getHeaderValue($bodyPart[0], 'Content-Disposition'));
                if ($contentDisposition === 'inline') {
                    $inlineAttachments[] = $attachmentData;
                } else {
                    $result['attachments'][] = $attachmentData;
                }
            }
        }
        if (!empty($inlineAttachments) && strlen($result['html']) > 0) {
            foreach ($inlineAttachments as $inlineAttachment) {
                if (strlen($inlineAttachment['id']) > 0 && strlen($inlineAttachment['contentType']) > 0) {
                    if (strpos($result['html'], 'cid:' . $inlineAttachment['id'])) {
                        $result['html'] = str_replace('cid:' . $inlineAttachment['id'], 'data:' . $inlineAttachment['contentType'] . ';base64,' . $inlineAttachment['base64Content'], $result['html']);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * 
     * @param string $text
     * @return string
     */
    private function decodeMIMEEncodedText(string $text): string
    {
        $result = '';
        $elements = imap_mime_header_decode($text);
        for ($i = 0; $i < count($elements); $i++) {
            $result .= $elements[$i]->text;
        }
        return $result;
    }

    /**
     * 
     * @param string $headers
     * @return array
     */
    private function parseHeaders(string $headers): array
    {
        $lines = explode("\r\n", trim($headers));
        $temp = [];
        foreach ($lines as $line) {
            if (preg_match('/^[a-zA-Z0-9]/', $line) === 1) {
                $temp[] = trim($line);
            } else {
                if (sizeof($temp) > 0) {
                    $temp[sizeof($temp) - 1] .= ' ' . trim($line);
                } else {
                    $temp[] = trim($line);
                }
            }
        }
        $result = [];
        foreach ($temp as $line) {
            $parts = explode(':', $line, 2);
            $result[] = [trim($parts[0]), isset($parts[1]) ? trim($parts[1]) : ''];
        }
        return $result;
    }

    /**
     * 
     * @param array $headers
     * @param string $name
     * @return string
     */
    private function getHeaderValue(array $headers, string $name): string
    {
        $name = strtolower($name);
        foreach ($headers as $header) {
            if (strtolower($header[0]) == $name) {
                return $header[1];
            }
        }
        return '';
    }

    /**
     * 
     * @param array $headers
     * @param string $name
     * @return array
     */
    private function getHeaderValueAndOptions(array $headers, string $name): array
    {
        $name = strtolower($name);
        foreach ($headers as $header) {
            if (strtolower($header[0]) == $name) {
                $parts = explode(';', trim($header[1]));
                $value = trim($parts[0]);
                $options = [];
                unset($parts[0]);
                if (!empty($parts)) {
                    foreach ($parts as $part) {
                        $part = trim($part);
                        if (isset($part{0})) {
                            $optionParts = explode('=', $part, 2);
                            if (sizeof($optionParts) === 2) {
                                $options[strtolower(trim($optionParts[0]))] = trim(trim(trim($optionParts[1]), '"\''));
                            }
                        }
                    }
                }
                return [$value, $options];
            }
        }
        return ['', []];
    }

    /**
     * 
     * @param string $address
     * @return array
     */
    private function parseEmailAdress(string $address): array
    {
        $matches = [];
        preg_match("/(.*)\<(.*)\>/", $address, $matches);
        if (sizeof($matches) === 3) {
            return [$matches[2], $this->decodeMIMEEncodedText(trim(trim(trim($matches[1]), '"\'')))];
        }
        return [$address, ''];
    }

    /**
     * 
     * @param string $email
     * @param string $parentContentType
     * @return array
     */
    private function getBodyParts(string $email, string $parentContentType = null): array
    {
        if ($parentContentType === null || $parentContentType === 'multipart/alternative' || $parentContentType === 'multipart/related' || $parentContentType === 'multipart/mixed' || $parentContentType === 'multipart/signed') {
            // First 2 lines separate the headers from the body
            $parts = explode("\r\n\r\n", $email, 2);
            $headers = $this->parseHeaders(trim($parts[0]));
            // When there is boundary
            $contentTypeData = $this->getHeaderValueAndOptions($headers, 'Content-Type');
            $contentType = $contentTypeData[0];
            $boundary = isset($contentTypeData[1]['boundary']) ? $contentTypeData[1]['boundary'] : '';
            if (strlen($boundary) > 0) {
                $parts = explode('--' . $boundary, $email, 2);
                $headers = $this->parseHeaders(trim($parts[0]));
                $body = '--' . $boundary . (isset($parts[1]) ? trim($parts[1]) : '');
            } else {
                $body = isset($parts[1]) ? trim($parts[1]) : '';
            }
        } else {
            $headers = [];
            $body = trim($email);
        }

        if (strlen($body) === 0) {
            return [];
        } else {
            $contentTypeData = $this->getHeaderValueAndOptions($headers, 'Content-Type');
            $contentType = $contentTypeData[0];
            $boundary = isset($contentTypeData[1]['boundary']) ? $contentTypeData[1]['boundary'] : '';
            if (strlen($boundary) > 0) {
                $startIndex = strpos($body, '--' . $boundary) + strlen($boundary) + 2;
                $endIndex = strpos($body, '--' . $body . '--') - 2;
                $bodyParts = explode('--' . $boundary, substr($body, $startIndex, $endIndex - $startIndex));
                $bodyParts = array_map('trim', $bodyParts);
                $temp = [];
                foreach ($bodyParts as $bodyPart) {
                    $childBodyParts = $this->getBodyParts($bodyPart, $contentType);
                    $temp = array_merge($temp, $childBodyParts);
                }
                $bodyParts = $temp;
            } else {
                $bodyParts = [[$headers, trim($body)]];
            }
            return $bodyParts;
        }
    }

    /**
     * 
     * @param array $headers
     * @param string $body
     * @return string
     */
    private function decodeBodyPart(array $headers, string $body): string
    {
        $contentTypeData = $this->getHeaderValueAndOptions($headers, 'Content-Type');

        $contentTransferEncoding = $this->getHeaderValue($headers, 'Content-Transfer-Encoding');
        if ($contentTransferEncoding === 'base64') {
            $body = base64_decode(preg_replace('/((\r?\n)*)/', '', $body));
        } elseif ($contentTransferEncoding === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        } elseif ($contentTransferEncoding === '7bit') {
            // gurmi
            //$body = mb_convert_encoding(imap_utf7_decode($body), 'UTF-8', 'ISO-8859-1');
        }

        if (isset($contentTypeData[1]['charset']) && strtolower($contentTypeData[1]['charset']) !== 'utf-8') {
            $charset = strtolower($contentTypeData[1]['charset']);
            $encodings = mb_list_encodings();
            $found = false;
            foreach ($encodings as $encoding) {
                if (strtolower($encoding) === $charset) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $body = mb_convert_encoding($body, 'UTF-8', $charset);
            }
        }

        return $body;
    }

}
