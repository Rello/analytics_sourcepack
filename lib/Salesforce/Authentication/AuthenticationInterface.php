<?php

namespace OCA\Analytics_Sourcepack\Salesforce\Authentication;

interface AuthenticationInterface
{

    /**
     * @return mixed
     */
    public function getAccessToken();

    /**
     * @return mixed
     */
    public function getInstanceUrl();
}
