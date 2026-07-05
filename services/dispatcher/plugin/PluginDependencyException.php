<?php

class PluginDependencyException extends Exception
{
}

class MissingPluginException extends PluginDependencyException
{
}

class CircularDependencyException extends PluginDependencyException
{
}

class VersionConflictException extends PluginDependencyException
{
}
