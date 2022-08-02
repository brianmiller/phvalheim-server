#!/bin/bash

#curl -X GET "https://valheim.thunderstore.io/api/v1/package/" -H  "accept: application/json" -H  "X-CSRFToken: bFFKrJ6Rzai7fMXn3MWh4ZF1lYGRa99EHoWGGqf1ObRAS42U33PFifUYmZr8vJhT" > json


json=$(cat json)

#parents
for row in $(echo "${json}" | jq -r '.[] | @base64'); do
	_jq() {
		echo ${row} | base64 --decode | jq -r ${1}
	}	
	ts_name=$(echo $(_jq '.name'))
	ts_owner=$(echo $(_jq '.owner'))
	ts_package_url=$(echo $(_jq '.package_url'))
	ts_date_created=$(echo $(_jq '.date_created'))
	ts_date_updated=$(echo $(_jq '.date_updated'))
	ts_uuid4=$(echo $(_jq '.uuid4'))
	ts_verisons=$(echo $(_jq '.versions'))
	ts_deps=$(echo $(_jq '.dependencies'))

	#child (versions)
	for row in $(echo "${ts_versions}" | jq -r '.[] | @base64'); do 
	_jq() {
		echo ${row} | base64 --decode | jq -r ${1}
	}
	ts_version=$(echo $(_jq '.version_number'))
	done


   echo
   echo "$ts_name"
   echo "$ts_owner"
   echo "$ts_uuid4"
   echo "$ts_versions"



done


