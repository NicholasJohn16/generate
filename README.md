# Generate Command
The Generate Command is used to automatically generate sample data for testing and development.

## Installation
Installation is done through composer.  Since this is a dev tool, requiring it as a dev tool so it's not installed in production.

```
composer require nicholasjohn16/generate --dev
```

## Usage
The Genereate Command is integrated into Anahita's CLI. To use it, you just need to pass in the repo and entity name that you wish to generate sample data for. Optionally, you can pass in the number of entities to generate using the count argument. This is 10 by default.

```
anahita generate:sample repo.entity [--count|-c="..."] [relationships1] ... [relationshipsN]
```

For example:
```
anahita generate:sample people.person
```

The above command will generate 10 person entities.

### Relationships
Relationships can be specified when generating entities. For one to many relationships, you can optionally provide an id for the relationship by seperating the relationship name and id with a colon. If no id is provided, the child for the relationship will be randomly selected. 

For example if we wanted to randomly generate a note with an owner of id 2 with a random author, we'd use the below command:

```
anahita generate:sample notes.note owner:2 author
```

Many to many relationships are not yet supported.

### Attributes
Attributes are generated randomly based on their entity definition. Non-required attributes have a chance of being null. If the generated value is null and they have a default value, the default value will be used instead. Faker PHP Library is used to generate the random values.  The provider used for each attribute is based on the `config.json` settings. Each attribute type has it's own set of defaults, followed by defaults for each entity attributes. Attributes not listed in will be skipped over and not generated.

These defaults can be overriden and extended to include attributes for your own entities. For your own projects, create a `sample.json` file in the main directory of your Anahita installation and add an repo and entity object like below.

```
{
  "myrepo": {
    "myentity": {

    }
  }
}
```

Then within your entity, list any additional attributes that you'd like to be randomly generated. As well, you can optional override the provider used for the attribute type and any arguments that are passed to it. You can also override core defaults using the same method. Providers must return a string, int, boolean or DateTime object.

```
{
  "myrepo": {
    "myentity": {
      "bookISBN": {
        "provider": "isbn13"
      },
      "enabled": {
        "arguments": [50]
      },
      "subtitle": {}
    }
  }
}
```

