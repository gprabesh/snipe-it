<?php

namespace App\Classes;

class JsonUser
{
    public int $groups;
	public bool $vip;
	public string $first_name;
	public string $last_name;
	public string $username;
	public string $password;
	public string $password_confirmation;
	public string $email;
	public string $permissions;
	public bool $activated;
	public string $phone;
	public string $jobtitle;
	public int $manager_id;
	public string $employee_num;
	public string $notes;
	public int $company_id;
	public bool $two_factor_enrolled;
	public bool $two_factor_optin;
	public string $department_id;
	public int $location_id;
	public bool $remote;
	public string $start_date;
	public string $end_date;
	public string $country;
	public string $website;
	public string $address;
	public string $city;
	public string $state;
	public string $zip;

}
