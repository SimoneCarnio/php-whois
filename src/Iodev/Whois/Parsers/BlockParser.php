<?php

namespace Iodev\Whois\Parsers;

use Iodev\Whois\DomainInfo;
use Iodev\Whois\Response;
use Iodev\Whois\Helpers\GroupHelper;

class BlockParser extends CommonParser
{
    /** @var string */
    protected $headerKey = 'HEADER';

    /** @var array */
    protected $domainSubsets = [];

    /** @var array */
    protected $ownerSubsets = [];

    /** @var array */
    protected $registrarSubsets = [];

    /**
     * @param Response $response
     * @return DomainInfo
     */
    public function parseResponse(Response $response)
    {
        $groups = $this->groupsFromText($response->getText());
        
        $domainGroup = GroupHelper::findGroupHasSubsetOf($groups, $this->renderSubsets($this->domainSubsets, $response));
        $ownerGroup = GroupHelper::findGroupHasSubsetOf($groups, $this->renderSubsets($this->ownerSubsets, $response));
        $registrarGroup = GroupHelper::findGroupHasSubsetOf($groups, $this->renderSubsets($this->registrarSubsets, $response));

        $data = [
            "domainName" => GroupHelper::getAsciiServer($domainGroup, $this->domainKeys),
            "whoisServer" => GroupHelper::getAsciiServer($domainGroup, $this->whoisServerKeys),
            "nameServers" => GroupHelper::getAsciiServersComplex($domainGroup, $this->nameServersKeys, $this->nameServersKeysGroups),
            "creationDate" => GroupHelper::getUnixtime($domainGroup, $this->creationDateKeys),
            "expirationDate" => GroupHelper::getUnixtime($domainGroup, $this->expirationDateKeys),
            "owner" => GroupHelper::matchFirstIn([ $ownerGroup, $domainGroup ], $this->ownerKeys),
            "registrar" => GroupHelper::matchFirstIn([ $registrarGroup, $domainGroup], $this->registrarKeys),
            "states" => $this->parseStates(GroupHelper::matchFirst($domainGroup, $this->statesKeys)),
        ];
        if (empty($data["domainName"])) {
            return null;
        }

        $states = $data["states"];
        $firstState = !empty($states) ? mb_strtolower(trim($states[0])) : "";
        if (!empty($this->notRegisteredStatesDict[$firstState])) {
            return null;
        }

        if (empty($states)
            && empty($data["nameServers"])
            && empty($data["owner"])
            && empty($data["creationDate"])
            && empty($data["expirationDate"])
            && empty($data["registrar"])
        ) {
            return null;
        }

        return new DomainInfo($response, $data);
    }

    /**
     * @param array $subsets
     * @param Response $response
     * @return array
     */
    private function renderSubsets($subsets, Response $response)
    {
        array_walk_recursive($subsets, function(&$val) use ($response) {
            if ($val == '$domain') {
                $val = $response->getDomain();
            }
        });
        return $subsets;
    }

    /**
     * @param string $text
     * @param string $prevEmptyGroupText
     * @return array
     */
    protected function groupFromText($text, $prevEmptyGroupText = '')
    {
        $group = [];
        $header = null;
        foreach (preg_split('~\r\n|[\r\n]~u', $text) as $line) {
            if (isset($header) && ltrim($line, '%#*') !== $line) {
                continue;
            }
            $split = explode(':', ltrim($line, "%#*:;= \t\n\r\0\x0B"), 2);
            $k = isset($split[0]) ? trim($split[0], ".\t\n\r\0\x0B") : '';
            $v = isset($split[1]) ? trim($split[1]) : '';
            if (strlen($k) && strlen($v)) {
                $group = array_merge_recursive($group, [ $k => $v ]);
                continue;
            }
            if (!isset($header) && !isset($split[1])) {
                $k = trim($k, "%#*:;=[] \t\0\x0B");
                $header = strlen($k) ? $k : null;
            }
        }
        $header = isset($header) ? $header : trim($prevEmptyGroupText, "%#*:;=[]. \t\n\r\0\x0B");
        return !empty($header)
            ? array_merge_recursive($group, [$this->headerKey => $header])
            : $group;
    }
}