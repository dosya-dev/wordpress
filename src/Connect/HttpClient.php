<?php
namespace Dosya\Backup\Connect;

interface HttpClient
{
    /**
     * @param array{method:string,url:string,headers?:array<string,string>,body?:string|null,bodyFile?:array{path:string,offset:int,length:int}|null,timeout?:int} $req
     * @return array{status:int,headers:array<string,string>,body:string} header names are lower-case
     */
    public function send(array $req): array;
}
