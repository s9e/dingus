#!/bin/bash

cd "$(dirname $0)/.."
rm -f cache/*
composer up
php scripts/generateInclude.php
